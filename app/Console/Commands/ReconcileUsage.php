<?php

namespace App\Console\Commands;

use App\Billing\Money;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Console\Command;

/**
 * Compares what we recorded as Pixelforge's cost with Pixelforge's own
 * GET /v1/usage report for this API key, per UTC day of completion.
 */
class ReconcileUsage extends Command
{
    protected $signature = 'pixelforge:reconcile {--days=7 : Number of days back from today (UTC)}';

    protected $description = 'Check our recorded provider costs against Pixelforge /v1/usage';

    public function handle(PixelforgeClient $client): int
    {
        $end = now()->utc()->startOfDay();
        $start = $end->copy()->subDays(max(1, (int) $this->option('days')) - 1);

        $report = $client->usage($start->toDateString(), $end->toDateString());

        // Pixelforge bills on the UTC day a generation completed; only succeeded ones are billed.
        $ours = Generation::query()
            ->where('status', GenerationStatus::Succeeded)
            ->whereBetween('completed_at', [$start, $end->copy()->endOfDay()])
            ->get(['completed_at', 'provider_cost'])
            ->groupBy(fn (Generation $g) => $g->completed_at->utc()->toDateString());

        $rows = [];
        $mismatches = 0;
        foreach ($report['data'] as $day) {
            $mine = $ours->get($day['date'], collect());
            $ourCost = Money::format((int) $mine->sum('provider_cost'));
            $match = $ourCost === $day['cost_usd'] && $mine->count() === (int) $day['generations'];
            $mismatches += $match ? 0 : 1;

            $rows[] = [$day['date'], $day['generations'], $mine->count(), $day['cost_usd'], $ourCost, $match ? 'OK' : 'MISMATCH'];
        }

        $this->table(['Date (UTC)', 'Pixelforge gens', 'Our gens', 'Pixelforge cost', 'Our cost', ''], $rows);

        if ($mismatches > 0) {
            $this->error("{$mismatches} day(s) differ.");

            return self::FAILURE;
        }

        $this->info('All days match Pixelforge exactly.');

        return self::SUCCESS;
    }
}
