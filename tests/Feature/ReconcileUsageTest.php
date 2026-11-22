<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReconcileUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pixelforge.api_key' => 'pf_test', 'pixelforge.base_url' => 'https://pixelforge.test/v1']);
        Carbon::setTestNow('2026-11-22 12:00:00');
    }

    public function test_it_passes_when_costs_match_per_completion_day(): void
    {
        $this->generation('2026-11-22 09:00:00', 305_510_000);
        $this->generation('2026-11-22 23:59:59', 15_525_000);
        Generation::factory()->accepted('failed')->create(['provider_cost' => 0, 'completed_at' => '2026-11-22 10:00:00']);

        $this->fakeUsage('0.321035000', 2);

        $this->artisan('pixelforge:reconcile', ['--days' => 1])->assertSuccessful();
    }

    public function test_it_fails_when_costs_differ(): void
    {
        $this->generation('2026-11-22 09:00:00', 305_510_000);
        $this->fakeUsage('0.305510001', 1);

        $this->artisan('pixelforge:reconcile', ['--days' => 1])->assertFailed();
    }

    private function generation(string $completedAt, int $cost): void
    {
        Generation::factory()->accepted('succeeded')->create([
            'status' => GenerationStatus::Succeeded,
            'provider_cost' => $cost,
            'charge' => intdiv($cost * 5, 4),
            'completed_at' => $completedAt,
        ]);
    }

    private function fakeUsage(string $cost, int $generations): void
    {
        Http::fake(['pixelforge.test/v1/usage*' => Http::response([
            'object' => 'usage',
            'data' => [['date' => '2026-11-22', 'generations' => $generations, 'cost_usd' => $cost]],
            'total' => ['generations' => $generations, 'cost_usd' => $cost],
        ])]);
    }
}
