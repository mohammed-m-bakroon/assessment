<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pixelforge.api_key' => 'pf_test', 'pixelforge.base_url' => 'https://pixelforge.test/v1']);
        Carbon::setTestNow('2026-11-22 12:00:00');
    }

    public function test_only_admins_can_see_the_report(): void
    {
        $this->get('/admin/report')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/report')->assertForbidden();
    }

    public function test_it_reports_charges_costs_and_profit_per_day_and_user(): void
    {
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);

        $this->succeeded($alice, '2026-11-21 23:59:59', 305_510_000); // late on the 21st (UTC)
        $this->succeeded($alice, '2026-11-22 00:00:01', 15_525_000);
        $this->succeeded($bob, '2026-11-22 10:00:00', 60_440_000);
        Generation::factory()->for($bob)->accepted('failed')->create(['provider_cost' => 0, 'charge' => 0, 'completed_at' => '2026-11-22 11:00:00']);
        Generation::factory()->for($bob)->accepted('processing')->create(); // in flight: not in the report

        $this->fakeUsage([
            '2026-11-21' => ['1', '0.305510000'],
            '2026-11-22' => ['2', '0.075965000'],
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/report?start=2026-11-21&end=2026-11-22')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Report')
                ->where('report.totals.succeeded', 3)
                ->where('report.totals.failed', 1)
                ->where('report.totals.provider_cost', '0.381475000')
                ->where('report.totals.charged', '0.476843750')
                ->where('report.totals.profit', '0.095368750')
                // newest day first
                ->where('report.days.0.date', '2026-11-22')
                ->where('report.days.0.provider_cost', '0.075965000')
                ->where('report.days.0.users.0.user.name', 'Bob')
                ->where('report.days.1.date', '2026-11-21')
                ->where('report.days.1.provider_cost', '0.305510000')
                ->where('report.users.0.user.name', 'Alice')
                ->where('report.users.0.profit', '0.080258750')
                ->where('pixelforge.available', true)
                ->where('pixelforge.total.cost', '0.381475000')
                ->where('pixelforge.days.2026-11-22.cost', '0.075965000'));
    }

    public function test_days_without_activity_are_included_as_zero(): void
    {
        $this->fakeUsage([]);

        $this->actingAs($this->admin())
            ->get('/admin/report?start=2026-11-20&end=2026-11-22')
            ->assertInertia(fn (Assert $page) => $page
                ->has('report.days', 3)
                ->where('report.days.2.date', '2026-11-20')
                ->where('report.days.2.charged', '0.000000000'));
    }

    public function test_the_page_still_works_when_pixelforge_is_unreachable(): void
    {
        Http::fake(['pixelforge.test/*' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'Busy']], 503)]);

        $this->actingAs($this->admin())
            ->get('/admin/report')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('pixelforge.available', false)->has('report.days', 30));
    }

    public function test_deleting_a_user_keeps_their_generations_for_the_books(): void
    {
        $user = User::factory()->create();
        $generation = $this->succeeded($user, '2026-11-22 09:00:00', 60_440_000);

        $user->delete();

        $this->assertNull($generation->refresh()->user_id);
        $this->assertSame(60_440_000, $generation->provider_cost);
    }

    public function test_make_admin_command(): void
    {
        $user = User::factory()->create(['email' => 'ops@example.com']);

        $this->artisan('app:make-admin', ['email' => 'ops@example.com'])->assertSuccessful();
        $this->assertTrue($user->refresh()->is_admin);

        $this->artisan('app:make-admin', ['email' => 'ops@example.com', '--revoke' => true])->assertSuccessful();
        $this->assertFalse($user->refresh()->is_admin);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }

    private function succeeded(User $user, string $completedAt, int $cost): Generation
    {
        return Generation::factory()->for($user)->accepted('succeeded')->create([
            'status' => GenerationStatus::Succeeded,
            'provider_cost' => $cost,
            'charge' => intdiv($cost * 125, 100),
            'completed_at' => $completedAt,
        ]);
    }

    /** @param array<string, array{0: string, 1: string}> $days date => [generations, cost_usd] */
    private function fakeUsage(array $days): void
    {
        $data = collect($days)->map(fn ($d, $date) => ['date' => $date, 'generations' => (int) $d[0], 'cost_usd' => $d[1]])->values();
        $total = '0.'.str_pad((string) $data->sum(fn ($d) => (int) str_replace('.', '', $d['cost_usd'])), 9, '0', STR_PAD_LEFT);

        Http::fake(['pixelforge.test/v1/usage*' => Http::response([
            'object' => 'usage',
            'data' => $data->all(),
            'total' => ['generations' => $data->sum('generations'), 'cost_usd' => $total],
        ])]);
    }
}
