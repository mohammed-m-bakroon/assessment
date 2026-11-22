<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Pixelforge\GenerationSynchronizer;
use App\Pixelforge\WebhookSignature;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives Pixelforge webhooks.
 *
 * Delivery is at-least-once and unordered. Two layers make that safe:
 *  1. Each event id is stored once (unique index); a processed duplicate is acknowledged and skipped.
 *  2. GenerationSynchronizer::apply() is itself idempotent and only moves status forward,
 *     so even two copies processed at the same moment can't charge twice.
 *
 * The work is a single small DB transaction, so it runs inline and replies well
 * within Pixelforge's 10 second timeout. Any failure returns 500 so Pixelforge retries.
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookSignature $signature, GenerationSynchronizer $sync): JsonResponse
    {
        $payload = $request->getContent();

        if (! $signature->isValid($payload, $request->header('Pixelforge-Signature'))) {
            return response()->json(['error' => 'Invalid signature.'], 400);
        }

        $event = json_decode($payload, true);
        if (! is_array($event) || empty($event['id']) || empty($event['type'])) {
            return response()->json(['error' => 'Malformed event.'], 400);
        }

        $record = $this->record($event, $request);
        if ($record->processed_at !== null) {
            return response()->json(['status' => 'duplicate']);
        }

        if (str_starts_with($event['type'], 'generation.')) {
            $object = $event['data']['object'] ?? [];
            $generation = $sync->findFor($object);

            if ($generation === null) {
                // Not ours (e.g. created by another environment's key). Acknowledge so it isn't retried.
                Log::warning('Pixelforge webhook for unknown generation', ['event' => $event['id'], 'generation' => $object['id'] ?? null]);
            } else {
                $sync->apply($generation, $object);
            }
        }

        $record->update(['processed_at' => now()]);

        return response()->json(['status' => 'ok']);
    }

    private function record(array $event, Request $request): WebhookEvent
    {
        $attributes = [
            'type' => $event['type'],
            'provider_generation_id' => $event['data']['object']['id'] ?? null,
            'payload' => $event,
            'delivery_attempt' => (int) $request->header('Pixelforge-Delivery-Attempt') ?: null,
        ];

        try {
            return WebhookEvent::firstOrCreate(['event_id' => $event['id']], $attributes);
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery of the same event inserted it first.
            return WebhookEvent::where('event_id', $event['id'])->firstOrFail();
        }
    }
}
