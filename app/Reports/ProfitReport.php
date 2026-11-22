<?php

namespace App\Reports;

use App\Billing\Money;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Operator report: what users were charged, what Pixelforge charged us, and the
 * profit, per UTC day and per user.
 *
 * Days are the UTC day a generation *completed*, because that is how Pixelforge
 * attributes cost in GET /v1/usage. Grouping the same way is what lets the
 * provider-cost column match their report exactly.
 */
class ProfitReport
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
    ) {}

    public function build(): array
    {
        $rows = $this->rows();
        $users = User::whereIn('id', $rows->pluck('user_id')->unique())->get(['id', 'name', 'email'])->keyBy('id');

        $days = [];
        foreach (CarbonPeriod::create($this->start, $this->end) as $date) {
            $day = $date->toDateString();
            $dayRows = $rows->where('day', $day);

            $days[] = [
                'date' => $day,
                ...$this->totals($dayRows),
                'users' => $dayRows
                    ->map(fn ($row) => ['user' => $this->userSummary($users, $row->user_id), ...$this->totals(collect([$row]))])
                    ->sortByDesc('charged_nanos')
                    ->values()
                    ->all(),
            ];
        }

        $perUser = $rows->groupBy('user_id')
            ->map(fn (Collection $userRows, $userId) => ['user' => $this->userSummary($users, $userId), ...$this->totals($userRows)])
            ->sortByDesc('charged_nanos')
            ->values()
            ->all();

        return [
            'start' => $this->start->toDateString(),
            'end' => $this->end->toDateString(),
            'totals' => $this->totals($rows),
            'days' => array_reverse($days), // newest first
            'users' => $perUser,
        ];
    }

    /** One row per (UTC completion day, user), aggregated in the database. */
    private function rows(): Collection
    {
        return Generation::query()
            ->whereIn('status', [GenerationStatus::Succeeded, GenerationStatus::Failed])
            ->whereBetween('completed_at', [$this->start->startOfDay(), $this->end->endOfDay()])
            ->selectRaw('DATE(completed_at) as day, user_id')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as succeeded', [GenerationStatus::Succeeded->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed', [GenerationStatus::Failed->value])
            ->selectRaw('COALESCE(SUM(charge), 0) as charged')
            ->selectRaw('COALESCE(SUM(provider_cost), 0) as provider_cost')
            ->groupByRaw('DATE(completed_at), user_id')
            ->toBase()
            ->get()
            ->map(function ($row) {
                $row->day = substr((string) $row->day, 0, 10);

                return $row;
            });
    }

    private function totals(Collection $rows): array
    {
        $charged = (int) $rows->sum(fn ($r) => (int) $r->charged);
        $cost = (int) $rows->sum(fn ($r) => (int) $r->provider_cost);

        return [
            'succeeded' => (int) $rows->sum(fn ($r) => (int) $r->succeeded),
            'failed' => (int) $rows->sum(fn ($r) => (int) $r->failed),
            'charged' => Money::format($charged),
            'provider_cost' => Money::format($cost),
            'profit' => Money::format($charged - $cost),
            'charged_nanos' => $charged, // for sorting only
        ];
    }

    private function userSummary(Collection $users, int|string|null $userId): array
    {
        $user = blank($userId) ? null : $users->get((int) $userId);

        return ['id' => $user?->id, 'name' => $user?->name ?? 'Deleted account', 'email' => $user?->email];
    }
}
