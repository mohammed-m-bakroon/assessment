<?php

namespace App\Pixelforge;

use App\Billing\PricingCalculator;
use App\Billing\TokenUsage;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single place where a generation's status and cost change.
 *
 * Webhooks, the submit job's response and the polling fallback all hand the
 * Pixelforge generation object to apply(). It is safe to call any number of
 * times, in any order:
 *
 *  - The row is locked, so two deliveries of the same event can't race.
 *  - Status only moves forward (queued → processing → succeeded|failed), so a
 *    late 'processing' event after 'succeeded' changes nothing.
 *  - The cost is written only on the single transition into a final status,
 *    so a generation can never be charged twice.
 */
class GenerationSynchronizer
{
    public function __construct(private readonly PricingCalculator $pricing) {}

    /** Find our generation for a Pixelforge generation object (e.g. from a webhook). */
    public function findFor(array $object): ?Generation
    {
        $publicId = $object['metadata']['generation_id'] ?? null;

        // Prefer our own id from metadata: a webhook can arrive before the
        // create request has returned, i.e. before we know the provider id.
        return ($publicId ? Generation::where('public_id', $publicId)->first() : null)
            ?? (isset($object['id']) ? Generation::where('provider_id', $object['id'])->first() : null);
    }

    public function apply(Generation $generation, array $object): Generation
    {
        return DB::transaction(function () use ($generation, $object) {
            /** @var Generation $locked */
            $locked = Generation::whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->provider_id !== null && $locked->provider_id !== ($object['id'] ?? null)) {
                throw new \UnexpectedValueException(
                    "Pixelforge generation [{$object['id']}] does not belong to generation [{$locked->public_id}]."
                );
            }

            $incoming = GenerationStatus::from($object['status']);

            $locked->provider_id ??= $object['id'];
            $locked->provider_created_at ??= $this->time($object['created_at'] ?? null);
            $locked->last_synced_at = now();

            if ($this->overridesLocalRejection($locked)) {
                $locked->forceFill(['provider_cost' => null, 'charge' => null, 'error_code' => null, 'error_message' => null, 'completed_at' => null]);
                $this->transition($locked, $incoming, $object);
            } elseif ($locked->status->canMoveTo($incoming)) {
                $this->transition($locked, $incoming, $object);
            } elseif ($locked->status === GenerationStatus::Succeeded && $incoming === GenerationStatus::Succeeded) {
                // Same final state seen again (duplicate event or a poll): only
                // refresh the image link, which Pixelforge re-signs on each GET.
                $this->fillImage($locked, $object);
            }

            $locked->save();

            return $locked;
        });
    }

    /**
     * Mark a generation Pixelforge never accepted as failed (content policy,
     * validation, daily limit, or we gave up retrying). It costs nothing.
     */
    public function markRejected(Generation $generation, string $code, string $message): Generation
    {
        return DB::transaction(function () use ($generation, $code, $message) {
            /** @var Generation $locked */
            $locked = Generation::whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === GenerationStatus::Pending) {
                $locked->forceFill([
                    'status' => GenerationStatus::Failed,
                    'provider_cost' => 0,
                    'charge' => 0,
                    'error_code' => $code,
                    'error_message' => $message,
                    'completed_at' => now(),
                ])->save();
            }

            return $locked;
        });
    }

    /**
     * A generation we marked failed *before* Pixelforge ever confirmed it (e.g.
     * every submit attempt timed out) may still have been created on their side.
     * If Pixelforge later tells us about it, their record wins, so our costs keep
     * matching what they bill.
     */
    private function overridesLocalRejection(Generation $generation): bool
    {
        return $generation->status === GenerationStatus::Failed
            && $generation->getOriginal('provider_id') === null
            && $generation->provider_id !== null;
    }

    private function transition(Generation $generation, GenerationStatus $status, array $object): void
    {
        $generation->status = $status;
        $generation->started_at ??= $this->time($object['started_at'] ?? null);

        if ($status === GenerationStatus::Succeeded) {
            $usage = TokenUsage::fromArray($object['usage'] ?? throw new \UnexpectedValueException('Succeeded generation has no usage.'));
            $rates = $object['rates'] ?? throw new \UnexpectedValueException('Succeeded generation has no rates.');

            $generation->usage = $usage->toArray();
            $generation->rates = $rates;
            $generation->provider_cost = $this->pricing->providerCost($usage, $rates);
            $generation->charge = $this->pricing->userCharge($generation->provider_cost);
            $generation->completed_at = $this->time($object['completed_at'] ?? null) ?? now();
            $this->fillImage($generation, $object);
        }

        if ($status === GenerationStatus::Failed) {
            // Pixelforge doesn't bill failed generations, so neither do we.
            $generation->provider_cost = 0;
            $generation->charge = 0;
            $generation->rates = $object['rates'] ?? $generation->rates;
            $generation->error_code = $object['error']['code'] ?? 'generation_failed';
            $generation->error_message = $object['error']['message'] ?? 'The generation failed.';
            $generation->completed_at = $this->time($object['completed_at'] ?? null) ?? now();
        }
    }

    private function fillImage(Generation $generation, array $object): void
    {
        if (! empty($object['image_url'])) {
            $generation->image_url = $object['image_url'];
            $generation->image_url_expires_at = $this->time($object['image_url_expires_at'] ?? null);
        }
    }

    private function time(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value)->utc() : null;
    }
}
