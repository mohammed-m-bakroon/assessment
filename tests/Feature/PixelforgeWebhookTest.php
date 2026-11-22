<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Pixelforge\WebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PixelforgeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['pixelforge.webhook_secret' => self::SECRET]);
    }

    public function test_succeeded_event_records_the_exact_cost_and_image(): void
    {
        $generation = Generation::factory()->accepted('processing')->create();

        $this->deliver($this->event('evt_1', 'succeeded', $generation))->assertOk();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(305_510_000, $generation->provider_cost); // $0.30551
        $this->assertSame(381_887_500, $generation->charge);        // + 25%
        $this->assertSame('https://pixelforge.test/image.png', $generation->image_url);
        $this->assertSame(3184, $generation->usage['reasoning_tokens']);
    }

    public function test_a_duplicate_event_is_recorded_once(): void
    {
        $generation = Generation::factory()->accepted('processing')->create();
        $event = $this->event('evt_dup', 'succeeded', $generation);

        $this->deliver($event)->assertOk();
        $this->deliver($event)->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame(381_887_500, $generation->refresh()->charge);
    }

    public function test_the_same_outcome_under_two_event_ids_is_still_charged_once(): void
    {
        $generation = Generation::factory()->accepted('processing')->create();

        $this->deliver($this->event('evt_a', 'succeeded', $generation))->assertOk();
        // A different event carrying different usage must not overwrite the recorded charge.
        $this->deliver($this->event('evt_b', 'succeeded', $generation, ['reasoning_tokens' => 1]))->assertOk();

        $this->assertSame(381_887_500, $generation->refresh()->charge);
    }

    public function test_a_late_processing_event_does_not_move_a_finished_generation_back(): void
    {
        $generation = Generation::factory()->accepted('queued')->create();

        $this->deliver($this->event('evt_done', 'succeeded', $generation))->assertOk();
        $this->deliver($this->event('evt_late', 'processing', $generation))->assertOk();

        $this->assertSame(GenerationStatus::Succeeded, $generation->refresh()->status);
        $this->assertSame(381_887_500, $generation->charge);
    }

    public function test_succeeded_cannot_be_overwritten_by_failed_or_vice_versa(): void
    {
        $failed = Generation::factory()->accepted('processing')->create();
        $this->deliver($this->event('evt_f', 'failed', $failed))->assertOk();
        $this->deliver($this->event('evt_s', 'succeeded', $failed))->assertOk();

        $this->assertSame(GenerationStatus::Failed, $failed->refresh()->status);
        $this->assertSame(0, $failed->charge);
    }

    public function test_failed_generations_cost_nothing(): void
    {
        $generation = Generation::factory()->accepted('processing')->create();

        $this->deliver($this->event('evt_fail', 'failed', $generation))->assertOk();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame(0, $generation->charge);
        $this->assertSame(0, $generation->provider_cost);
        $this->assertSame('generation_failed', $generation->error_code);
    }

    public function test_a_webhook_can_arrive_before_the_create_request_returned(): void
    {
        // We don't know the Pixelforge id yet; the event is matched via our id in metadata.
        $generation = Generation::factory()->create(['status' => GenerationStatus::Pending]);

        $this->deliver($this->event('evt_early', 'processing', $generation, providerId: 'gen_EARLY'))->assertOk();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Processing, $generation->status);
        $this->assertSame('gen_EARLY', $generation->provider_id);
    }

    public function test_an_invalid_signature_is_rejected(): void
    {
        $generation = Generation::factory()->accepted('processing')->create();
        $body = json_encode($this->event('evt_x', 'succeeded', $generation));

        $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'HTTP_PIXELFORGE_SIGNATURE' => 't='.time().',v1='.WebhookSignature::sign($body, time(), 'wrong_secret'),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(400);

        $this->assertSame(GenerationStatus::Processing, $generation->refresh()->status);
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_an_old_signature_is_rejected_as_a_replay(): void
    {
        $generation = Generation::factory()->accepted('processing')->create();

        $this->deliver($this->event('evt_old', 'succeeded', $generation), time() - 301)->assertStatus(400);

        $this->assertSame(GenerationStatus::Processing, $generation->refresh()->status);
    }

    public function test_events_for_unknown_generations_and_pings_are_acknowledged(): void
    {
        $this->deliver(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'data' => []])->assertOk();

        $stranger = Generation::factory()->make(['public_id' => '01UNKNOWN']);
        $this->deliver($this->event('evt_unknown', 'succeeded', $stranger, providerId: 'gen_NOT_OURS'))->assertOk();
    }

    // --- helpers -------------------------------------------------------------

    private function deliver(array $event, ?int $timestamp = null)
    {
        $body = json_encode($event);
        $timestamp ??= time();

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1=".WebhookSignature::sign($body, $timestamp, self::SECRET),
            'HTTP_PIXELFORGE_EVENT_ID' => $event['id'],
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    private function event(string $id, string $status, Generation $generation, array $usage = [], ?string $providerId = null): array
    {
        $object = [
            'id' => $providerId ?? $generation->provider_id,
            'object' => 'generation',
            'status' => $status,
            'metadata' => ['generation_id' => $generation->public_id],
            'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
            'usage' => null,
            'image_url' => null,
            'error' => null,
            'created_at' => '2026-11-04T14:03:11Z',
            'started_at' => $status === 'queued' ? null : '2026-11-04T14:03:13Z',
            'completed_at' => null,
        ];

        if ($status === 'succeeded') {
            $object['usage'] = [...['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184], ...$usage];
            $object['image_url'] = 'https://pixelforge.test/image.png';
            $object['image_url_expires_at'] = '2026-11-05T14:03:29Z';
            $object['completed_at'] = '2026-11-04T14:03:29Z';
        }

        if ($status === 'failed') {
            $object['error'] = ['code' => 'generation_failed', 'message' => 'The generation failed. You were not charged.'];
            $object['completed_at'] = '2026-11-04T14:03:29Z';
        }

        return ['id' => $id, 'object' => 'event', 'type' => "generation.{$status}", 'created_at' => '2026-11-04T14:03:29Z', 'data' => ['object' => $object]];
    }
}
