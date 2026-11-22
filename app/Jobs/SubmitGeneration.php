<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\GenerationSynchronizer;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends a generation to Pixelforge.
 *
 * Rate limits, the 3-at-a-time concurrency limit, overloads and network errors
 * all mean "try again later": the job releases itself back onto the queue
 * (honouring Retry-After) instead of failing. Every attempt sends the same
 * Idempotency-Key, so a retry after a timeout can never create - or bill - a
 * second generation.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /** Give up on submitting after this long; the generation is then failed (and free). */
    public const GIVE_UP_AFTER_MINUTES = 30;

    public int $tries = 0;          // unlimited attempts, bounded by retryUntil()

    public int $maxExceptions = 3;  // unexpected exceptions (bugs), not Pixelforge errors

    public function __construct(public Generation $generation) {}

    public function retryUntil(): \DateTimeInterface
    {
        return $this->generation->created_at->addMinutes(self::GIVE_UP_AFTER_MINUTES);
    }

    public function handle(PixelforgeClient $client, GenerationSynchronizer $sync): void
    {
        $generation = $this->generation->fresh();

        // Already accepted (e.g. a webhook or an earlier attempt got there first).
        if ($generation->status !== GenerationStatus::Pending) {
            return;
        }

        $generation->increment('submit_attempts');

        try {
            $object = $client->createGeneration([
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'reference_images' => $generation->reference_images,
                'metadata' => ['generation_id' => $generation->public_id],
            ], $generation->idempotency_key);
        } catch (PixelforgeException $e) {
            $this->handleError($generation, $e, $sync);

            return;
        }

        $sync->apply($generation, $object);
    }

    private function handleError(Generation $generation, PixelforgeException $e, GenerationSynchronizer $sync): void
    {
        if (! $e->isRetryable()) {
            // e.g. content_policy_violation, validation_failed, daily_limit_exceeded:
            // nothing was created at Pixelforge, so nothing is charged.
            $sync->markRejected($generation, $e->errorCode, $e->getMessage());

            return;
        }

        $generation->update(['last_submit_error' => $e->errorCode]);

        $this->release($this->backoffSeconds($generation->submit_attempts, $e->retryAfter));
    }

    /** Retry-After if Pixelforge sent one, else exponential backoff; plus jitter so queued jobs don't stampede. */
    private function backoffSeconds(int $attempt, ?int $retryAfter): int
    {
        $base = $retryAfter ?? min(60, 2 ** min($attempt, 6));

        return $base + random_int(0, max(1, intdiv($base, 2)));
    }

    /** Ran out of time (or hit a bug): the user is not charged. */
    public function failed(?Throwable $e): void
    {
        app(GenerationSynchronizer::class)->markRejected(
            $this->generation,
            'submission_failed',
            'Pixelforge was too busy to accept this generation. You were not charged; please try again.',
        );
    }
}
