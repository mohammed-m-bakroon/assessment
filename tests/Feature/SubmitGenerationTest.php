<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SubmitGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pixelforge.api_key' => 'pf_test', 'pixelforge.base_url' => 'https://pixelforge.test/v1']);
    }

    public function test_it_submits_with_an_idempotency_key_and_our_id_in_metadata(): void
    {
        Http::fake(['pixelforge.test/*' => Http::response($this->queued('gen_ABC'), 202)]);
        $generation = Generation::factory()->create(['reference_images' => ['https://example.com/a.png']]);

        $this->run_($generation);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertSame('gen_ABC', $generation->provider_id);

        Http::assertSent(fn (Request $r) => $r->hasHeader('Idempotency-Key', $generation->idempotency_key)
            && $r->hasHeader('Authorization', 'Bearer pf_test')
            && $r['metadata'] === ['generation_id' => $generation->public_id]
            && $r['reference_images'] === ['https://example.com/a.png']
            && array_keys($r->data()) === ['prompt', 'size', 'quality', 'reference_images', 'metadata']);
    }

    public function test_retries_reuse_the_same_idempotency_key(): void
    {
        Http::fakeSequence('pixelforge.test/*')
            ->push(['error' => ['code' => 'overloaded', 'type' => 'api_error', 'message' => 'Busy']], 503, ['Retry-After' => '2'])
            ->push($this->queued('gen_ABC'), 202);
        $generation = Generation::factory()->create();

        $first = $this->run_($generation);
        $first->assertReleased();
        $this->assertSame(GenerationStatus::Pending, $generation->refresh()->status);
        $this->assertSame('overloaded', $generation->last_submit_error);

        $this->run_($generation);
        $this->assertSame(GenerationStatus::Queued, $generation->refresh()->status);

        $keys = Http::recorded()->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0])->unique();
        $this->assertCount(1, $keys);
    }

    public function test_concurrency_and_rate_limits_wait_instead_of_failing(): void
    {
        $codes = ['concurrency_limit_exceeded', 'rate_limit_exceeded'];
        $responses = Http::fakeSequence('pixelforge.test/*');
        foreach ($codes as $code) {
            $responses->push(['error' => ['code' => $code, 'type' => 'rate_limit_error', 'message' => 'Slow down']], 429, ['Retry-After' => '5']);
        }

        foreach ($codes as $code) {
            $generation = Generation::factory()->create();

            $this->run_($generation)->assertReleased();

            $this->assertSame(GenerationStatus::Pending, $generation->refresh()->status);
            $this->assertSame($code, $generation->last_submit_error);
        }
    }

    public function test_a_rejected_prompt_fails_and_costs_nothing(): void
    {
        Http::fake(['pixelforge.test/*' => Http::response(
            ['error' => ['code' => 'content_policy_violation', 'type' => 'invalid_request_error', 'message' => 'Prompt rejected.']], 400,
        )]);
        $generation = Generation::factory()->create();

        $this->run_($generation)->assertNotReleased();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame(0, $generation->charge);
        $this->assertSame('content_policy_violation', $generation->error_code);
    }

    public function test_it_does_nothing_if_a_webhook_already_moved_the_generation_on(): void
    {
        Http::fake();
        $generation = Generation::factory()->accepted('processing')->create();

        $this->run_($generation);

        Http::assertNothingSent();
    }

    public function test_giving_up_marks_the_generation_failed_and_free(): void
    {
        $generation = Generation::factory()->create();

        (new SubmitGeneration($generation))->failed(null);

        $this->assertSame(GenerationStatus::Failed, $generation->refresh()->status);
        $this->assertSame(0, $generation->charge);
    }

    public function test_storing_a_generation_saves_the_estimate_and_queues_submission(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post('/generations', [
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png', ''],
        ])->assertRedirect('/generations');

        $generation = $user->generations()->sole();
        $this->assertSame(GenerationStatus::Pending, $generation->status);
        $this->assertSame(467_343_750, $generation->estimated_charge_max); // docs example + 25%
        $this->assertCount(2, $generation->reference_images);
        Queue::assertPushed(SubmitGeneration::class);
    }

    public function test_invalid_input_is_rejected_before_reaching_pixelforge(): void
    {
        Queue::fake();

        $this->actingAs(User::factory()->create())->post('/generations', [
            'prompt' => '',
            'size' => '800x600',
            'quality' => 'ultra',
            'reference_images' => ['ftp://example.com/a.png'],
        ])->assertSessionHasErrors(['prompt', 'size', 'quality', 'reference_images.0']);

        Queue::assertNothingPushed();
    }

    public function test_the_estimate_endpoint_returns_a_price_range(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/generate/estimate?prompt_length=400&size=1536x1024&quality=high&reference_images=2')
            ->assertOk()
            ->assertJson(['charge_min' => '0.357781250', 'charge_max' => '0.467343750']);
    }

    public function test_users_only_see_their_own_generations(): void
    {
        $mine = Generation::factory()->create();
        $theirs = Generation::factory()->create();

        $this->actingAs($mine->user)
            ->getJson('/generations/status?'.http_build_query(['ids' => [$mine->public_id, $theirs->public_id]]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->public_id);
    }

    private function run_(Generation $generation): SubmitGeneration
    {
        $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        return $job;
    }

    private function queued(string $id): array
    {
        return [
            'id' => $id, 'object' => 'generation', 'status' => 'queued',
            'metadata' => [], 'usage' => null, 'image_url' => null, 'error' => null,
            'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
            'created_at' => '2026-11-04T14:03:11Z', 'started_at' => null, 'completed_at' => null,
        ];
    }
}
