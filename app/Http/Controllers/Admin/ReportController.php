<?php

namespace App\Http\Controllers\Admin;

use App\Billing\Money;
use App\Http\Controllers\Controller;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Reports\ProfitReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    /** Pixelforge's /v1/usage accepts at most 92 days. */
    private const MAX_DAYS = 92;

    public function __invoke(Request $request, PixelforgeClient $client): Response
    {
        $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
        ]);

        $today = CarbonImmutable::now('UTC')->startOfDay();
        $end = $request->filled('end') ? CarbonImmutable::parse($request->input('end'), 'UTC') : $today;
        $start = $request->filled('start') ? CarbonImmutable::parse($request->input('start'), 'UTC') : $end->subDays(29);
        $start = $start->max($end->subDays(self::MAX_DAYS - 1)); // clamp to the API's window

        $report = (new ProfitReport($start, $end))->build();

        return Inertia::render('admin/Report', [
            'report' => $report,
            'pixelforge' => $this->pixelforgeUsage($client, $report['start'], $report['end']),
        ]);
    }

    /**
     * Pixelforge's own numbers for the same days, so the page can show whether our
     * recorded provider cost matches theirs. Cached briefly to spare the rate limit.
     */
    private function pixelforgeUsage(PixelforgeClient $client, string $start, string $end): array
    {
        try {
            $usage = Cache::remember("pixelforge-usage:{$start}:{$end}", 60, fn () => $client->usage($start, $end));
        } catch (PixelforgeException $e) {
            return ['available' => false, 'error' => $e->getMessage(), 'days' => [], 'total' => null];
        }

        return [
            'available' => true,
            'error' => null,
            'days' => collect($usage['data'])->mapWithKeys(fn ($day) => [$day['date'] => [
                'generations' => (int) $day['generations'],
                'cost' => Money::format(Money::toNanos($day['cost_usd'])),
            ]])->all(),
            'total' => [
                'generations' => (int) $usage['total']['generations'],
                'cost' => Money::format(Money::toNanos($usage['total']['cost_usd'])),
            ],
        ];
    }
}
