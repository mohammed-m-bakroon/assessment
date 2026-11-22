<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatUsd } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, ChevronRight, CircleHelp } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

interface Totals {
    succeeded: number;
    failed: number;
    charged: string;
    provider_cost: string;
    profit: string;
}

interface UserRef {
    id: number | null;
    name: string;
    email: string | null;
}

interface Day extends Totals {
    date: string;
    users: (Totals & { user: UserRef })[];
}

interface PixelforgeDay {
    generations: number;
    cost: string;
}

const props = defineProps<{
    report: {
        start: string;
        end: string;
        totals: Totals;
        days: Day[];
        users: (Totals & { user: UserRef })[];
    };
    pixelforge: {
        available: boolean;
        error: string | null;
        days: Record<string, PixelforgeDay>;
        total: PixelforgeDay | null;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Admin report', href: '/admin/report' }];

const range = reactive({ start: props.report.start, end: props.report.end });
const hideEmptyDays = ref(true);
const expanded = reactive(new Set<string>());

function apply(start = range.start, end = range.end) {
    router.get(route('admin.report'), { start, end }, { preserveState: false, preserveScroll: true });
}

function lastDays(days: number) {
    const end = new Date();
    const start = new Date(Date.now() - (days - 1) * 86_400_000);
    apply(start.toISOString().slice(0, 10), end.toISOString().slice(0, 10));
}

const visibleDays = computed(() =>
    props.report.days.filter((d) => !hideEmptyDays.value || d.succeeded + d.failed > 0 || (props.pixelforge.days[d.date]?.generations ?? 0) > 0),
);

// Decimal strings compare exactly as strings (same 9-decimal format on both sides).
const totalMatches = computed(() => props.pixelforge.total?.cost === props.report.totals.provider_cost);
const dayMatches = (d: Day) => props.pixelforge.days[d.date]?.cost === d.provider_cost;

// Display only: a percentage doesn't need exact arithmetic.
const margin = computed(() => {
    const charged = parseFloat(props.report.totals.charged);
    return charged > 0 ? `${((parseFloat(props.report.totals.profit) / charged) * 100).toFixed(1)}%` : '—';
});

function toggle(date: string) {
    if (expanded.has(date)) expanded.delete(date);
    else expanded.add(date);
}

function formatDay(date: string) {
    return new Date(`${date}T00:00:00Z`).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
}
</script>

<template>
    <Head title="Admin report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4 lg:p-6">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Credit spend, provider cost &amp; profit</h1>
                <p class="text-sm text-muted-foreground">
                    Days are UTC and a generation counts on the day it <em>completed</em>, the same way Pixelforge's usage report does.
                </p>
            </div>

            <!-- Range -->
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="apply()">
                <div class="grid gap-1">
                    <Label for="start">From</Label>
                    <Input id="start" v-model="range.start" type="date" class="w-40" />
                </div>
                <div class="grid gap-1">
                    <Label for="end">To</Label>
                    <Input id="end" v-model="range.end" type="date" class="w-40" />
                </div>
                <Button type="submit">Apply</Button>
                <div class="flex gap-1">
                    <Button type="button" variant="ghost" size="sm" @click="lastDays(1)">Today</Button>
                    <Button type="button" variant="ghost" size="sm" @click="lastDays(7)">7 days</Button>
                    <Button type="button" variant="ghost" size="sm" @click="lastDays(30)">30 days</Button>
                    <Button type="button" variant="ghost" size="sm" @click="lastDays(92)">92 days</Button>
                </div>
            </form>

            <!-- Reconciliation banner -->
            <div
                v-if="!pixelforge.available"
                class="flex items-start gap-2 rounded-lg border bg-muted/50 px-4 py-3 text-sm text-muted-foreground"
            >
                <CircleHelp class="mt-0.5 size-4 shrink-0" />
                Couldn't load Pixelforge's usage report to compare ({{ pixelforge.error }}).
            </div>
            <div
                v-else-if="totalMatches"
                class="flex items-start gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300"
            >
                <CheckCircle2 class="mt-0.5 size-4 shrink-0" />
                Provider cost matches Pixelforge's <code>/v1/usage</code> for this period exactly: {{ formatUsd(pixelforge.total?.cost) }} across
                {{ pixelforge.total?.generations }} billed generations.
            </div>
            <div
                v-else
                class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                Pixelforge reports {{ formatUsd(pixelforge.total?.cost) }} for this period but we recorded {{ formatUsd(report.totals.provider_cost) }}. Days
                that differ are marked below. Generations still in flight are counted by Pixelforge only once they complete.
            </div>

            <!-- Totals -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border p-4">
                    <p class="text-sm text-muted-foreground">Charged to users</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ formatUsd(report.totals.charged) }}</p>
                </div>
                <div class="rounded-xl border p-4">
                    <p class="text-sm text-muted-foreground">Pixelforge cost</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ formatUsd(report.totals.provider_cost) }}</p>
                </div>
                <div class="rounded-xl border p-4">
                    <p class="text-sm text-muted-foreground">Profit</p>
                    <p class="text-2xl font-semibold tabular-nums text-emerald-700 dark:text-emerald-400">{{ formatUsd(report.totals.profit) }}</p>
                    <p class="text-xs text-muted-foreground">{{ margin }} of revenue</p>
                </div>
                <div class="rounded-xl border p-4">
                    <p class="text-sm text-muted-foreground">Generations</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ report.totals.succeeded }}</p>
                    <p class="text-xs text-muted-foreground">succeeded · {{ report.totals.failed }} failed (free)</p>
                </div>
            </div>

            <!-- Per day -->
            <section class="space-y-2">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="font-semibold">By day</h2>
                    <label class="flex items-center gap-2 text-sm text-muted-foreground">
                        <input v-model="hideEmptyDays" type="checkbox" class="rounded border-input" />
                        Hide days without activity
                    </label>
                </div>
                <div class="overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2 font-medium">Date (UTC)</th>
                                <th class="px-3 py-2 text-right font-medium">Succeeded / failed</th>
                                <th class="px-3 py-2 text-right font-medium">Charged</th>
                                <th class="px-3 py-2 text-right font-medium">Pixelforge cost (ours)</th>
                                <th class="px-3 py-2 text-right font-medium">Pixelforge reports</th>
                                <th class="px-3 py-2 text-right font-medium">Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="visibleDays.length === 0">
                                <td colspan="6" class="px-3 py-8 text-center text-muted-foreground">No completed generations in this period.</td>
                            </tr>
                            <template v-for="day in visibleDays" :key="day.date">
                                <tr class="border-t" :class="day.users.length && 'cursor-pointer hover:bg-muted/30'" @click="day.users.length && toggle(day.date)">
                                    <td class="px-3 py-2">
                                        <span class="flex items-center gap-1">
                                            <ChevronRight
                                                :class="['size-4 text-muted-foreground transition-transform', expanded.has(day.date) && 'rotate-90', !day.users.length && 'invisible']"
                                            />
                                            {{ formatDay(day.date) }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ day.succeeded }} / {{ day.failed }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ formatUsd(day.charged) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ formatUsd(day.provider_cost) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">
                                        <template v-if="pixelforge.available && pixelforge.days[day.date]">
                                            <span class="inline-flex items-center gap-1">
                                                {{ formatUsd(pixelforge.days[day.date].cost) }}
                                                <CheckCircle2 v-if="dayMatches(day)" class="size-3.5 text-emerald-600" aria-label="Matches" />
                                                <AlertTriangle v-else class="size-3.5 text-amber-600" aria-label="Differs" />
                                            </span>
                                        </template>
                                        <span v-else class="text-muted-foreground">—</span>
                                    </td>
                                    <td class="px-3 py-2 text-right font-medium tabular-nums">{{ formatUsd(day.profit) }}</td>
                                </tr>
                                <tr v-for="row in expanded.has(day.date) ? day.users : []" :key="`${day.date}-${row.user.id}`" class="bg-muted/20 text-xs">
                                    <td class="py-1.5 pl-10 pr-3">
                                        {{ row.user.name }} <span class="text-muted-foreground">{{ row.user.email }}</span>
                                    </td>
                                    <td class="px-3 py-1.5 text-right tabular-nums">{{ row.succeeded }} / {{ row.failed }}</td>
                                    <td class="px-3 py-1.5 text-right tabular-nums">{{ formatUsd(row.charged) }}</td>
                                    <td class="px-3 py-1.5 text-right tabular-nums">{{ formatUsd(row.provider_cost) }}</td>
                                    <td class="px-3 py-1.5" />
                                    <td class="px-3 py-1.5 text-right tabular-nums">{{ formatUsd(row.profit) }}</td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-muted-foreground">Click a day to see each user's share.</p>
            </section>

            <!-- Per user -->
            <section class="space-y-2">
                <h2 class="font-semibold">By user</h2>
                <div class="overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2 font-medium">User</th>
                                <th class="px-3 py-2 text-right font-medium">Succeeded / failed</th>
                                <th class="px-3 py-2 text-right font-medium">Charged</th>
                                <th class="px-3 py-2 text-right font-medium">Pixelforge cost</th>
                                <th class="px-3 py-2 text-right font-medium">Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="report.users.length === 0">
                                <td colspan="5" class="px-3 py-8 text-center text-muted-foreground">No users with completed generations in this period.</td>
                            </tr>
                            <tr v-for="row in report.users" :key="row.user.id ?? 'deleted'" class="border-t">
                                <td class="px-3 py-2">
                                    {{ row.user.name }} <span class="block text-xs text-muted-foreground">{{ row.user.email }}</span>
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ row.succeeded }} / {{ row.failed }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ formatUsd(row.charged) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ formatUsd(row.provider_cost) }}</td>
                                <td class="px-3 py-2 text-right font-medium tabular-nums">{{ formatUsd(row.profit) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
