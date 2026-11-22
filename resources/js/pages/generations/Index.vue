<script setup lang="ts">
import GenerationStatusBadge from '@/components/GenerationStatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatUsd, formatUsdRange } from '@/lib/money';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { IN_FLIGHT, type Generation, type Paginated } from '@/types/generation';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useIntervalFn } from '@vueuse/core';
import { CheckCircle2, ImageOff, Loader2, Sparkles, XCircle } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps<{ generations: Paginated<Generation> }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'History', href: '/generations' }];
const page = usePage<SharedData>();
const flash = computed(() => page.props.flash?.success);

// Local copy so live updates can be merged in without a full page reload.
const items = ref<Generation[]>([]);
watch(
    () => props.generations.data,
    (data) => (items.value = [...data]),
    { immediate: true },
);

const inFlightIds = computed(() => items.value.filter((g) => IN_FLIGHT.includes(g.status)).map((g) => g.id));

async function refreshStatuses() {
    if (inFlightIds.value.length === 0 || document.hidden) return;

    const query = new URLSearchParams();
    inFlightIds.value.forEach((id) => query.append('ids[]', id));

    try {
        const response = await fetch(`${route('generations.status')}?${query}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) return;
        const { data } = (await response.json()) as { data: Generation[] };
        const updates = new Map(data.map((g) => [g.id, g]));
        items.value = items.value.map((g) => updates.get(g.id) ?? g);
    } catch {
        // Network blip: try again on the next tick.
    }
}

useIntervalFn(refreshStatuses, 2500);

// Pixelforge image links expire after 24h; show a clear state instead of a broken image.
const brokenImages = reactive(new Set<string>());

function aspect(size: string): string {
    const [w, h] = size.split('x').map(Number);
    return `${w} / ${h}`;
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

function tokenSummary(g: Generation): string {
    if (!g.usage) return '';
    const u = g.usage;
    return [
        `${u.input_text_tokens.toLocaleString()} text`,
        u.input_image_tokens ? `${u.input_image_tokens.toLocaleString()} image` : null,
        `${u.output_tokens.toLocaleString()} output`,
        `${u.reasoning_tokens.toLocaleString()} reasoning`,
    ]
        .filter(Boolean)
        .join(' · ');
}

function waitingText(g: Generation): string {
    switch (g.waiting_reason) {
        case 'concurrency_limit_exceeded':
            return 'Pixelforge is already running the maximum number of generations. Yours will start automatically.';
        case 'rate_limit_exceeded':
            return 'Pixelforge is rate limiting requests. Retrying automatically.';
        case 'overloaded':
        case 'server_error':
        case 'connection_error':
            return 'Pixelforge is busy. Retrying automatically.';
        default:
            return 'Sending to Pixelforge…';
    }
}
</script>

<template>
    <Head title="History" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4 lg:p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight">Your generations</h1>
                    <p class="text-sm text-muted-foreground">Status updates live. You're charged the actual cost once an image is ready; failures are free.</p>
                </div>
                <Button as-child>
                    <Link :href="route('generations.create')"><Sparkles /> New generation</Link>
                </Button>
            </div>

            <div
                v-if="flash"
                class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300"
            >
                <CheckCircle2 class="size-4 shrink-0" /> {{ flash }}
            </div>

            <div v-if="items.length === 0" class="rounded-xl border border-dashed p-12 text-center">
                <p class="font-medium">No generations yet</p>
                <p class="mb-4 text-sm text-muted-foreground">Write a prompt and your images will show up here.</p>
                <Button as-child>
                    <Link :href="route('generations.create')"><Sparkles /> Generate your first image</Link>
                </Button>
            </div>

            <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <article v-for="g in items" :key="g.id" class="flex flex-col overflow-hidden rounded-xl border bg-card">
                    <!-- Image / status area -->
                    <div class="relative bg-muted" :style="{ aspectRatio: aspect(g.size) }">
                        <template v-if="g.status === 'succeeded' && g.image_url && !brokenImages.has(g.id)">
                            <a :href="g.image_url" target="_blank" rel="noopener">
                                <img :src="g.image_url" :alt="g.prompt" class="size-full object-cover" loading="lazy" @error="brokenImages.add(g.id)" />
                            </a>
                        </template>
                        <div v-else class="absolute inset-0 flex flex-col items-center justify-center gap-2 p-6 text-center text-sm text-muted-foreground">
                            <template v-if="g.status === 'succeeded'">
                                <ImageOff class="size-6" />
                                <span>Image link expired. Pixelforge keeps images for 24 hours.</span>
                            </template>
                            <template v-else-if="g.status === 'failed'">
                                <XCircle class="size-6 text-red-500" />
                                <span class="text-foreground">{{ g.error_message ?? 'The generation failed.' }}</span>
                                <span v-if="!/not charged/i.test(g.error_message ?? '')" class="text-xs">You were not charged.</span>
                            </template>
                            <template v-else>
                                <Loader2 class="size-6 animate-spin" />
                                <span v-if="g.status === 'pending'">{{ waitingText(g) }}</span>
                                <span v-else-if="g.status === 'queued'">Waiting in Pixelforge's queue…</span>
                                <span v-else>Generating… this usually takes 5–30 seconds.</span>
                            </template>
                        </div>
                    </div>

                    <!-- Details -->
                    <div class="flex flex-1 flex-col gap-3 p-4">
                        <div class="flex items-start justify-between gap-2">
                            <p class="line-clamp-3 text-sm" :title="g.prompt">{{ g.prompt }}</p>
                            <GenerationStatusBadge :status="g.status" :waiting-reason="g.waiting_reason" class="shrink-0" />
                        </div>

                        <p class="text-xs text-muted-foreground">
                            {{ g.size.replace('x', '×') }} · <span class="capitalize">{{ g.quality }}</span>
                            <template v-if="g.reference_images.length"> · {{ g.reference_images.length }} reference{{ g.reference_images.length > 1 ? 's' : '' }}</template>
                            · {{ formatDate(g.created_at) }}
                        </p>

                        <dl class="mt-auto grid grid-cols-2 gap-2 rounded-lg bg-muted/50 p-3 text-xs">
                            <div>
                                <dt class="text-muted-foreground">Estimated</dt>
                                <dd class="tabular-nums">{{ formatUsdRange(g.estimated_charge_min, g.estimated_charge_max) }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Charged</dt>
                                <dd class="font-semibold tabular-nums">
                                    <template v-if="g.status === 'failed'">Free</template>
                                    <template v-else-if="g.charge !== null">{{ formatUsd(g.charge) }}</template>
                                    <span v-else class="font-normal text-muted-foreground">When ready</span>
                                </dd>
                            </div>
                            <div v-if="g.usage" class="col-span-2 text-muted-foreground">{{ tokenSummary(g) }} tokens</div>
                        </dl>
                    </div>
                </article>
            </div>

            <nav v-if="generations.meta.last_page > 1" class="flex items-center justify-between text-sm">
                <span class="text-muted-foreground">{{ generations.meta.from }}–{{ generations.meta.to }} of {{ generations.meta.total }}</span>
                <div class="flex gap-2">
                    <Button variant="outline" size="sm" :disabled="!generations.links.prev" as-child>
                        <Link v-if="generations.links.prev" :href="generations.links.prev" preserve-scroll>Previous</Link>
                        <span v-else>Previous</span>
                    </Button>
                    <Button variant="outline" size="sm" :disabled="!generations.links.next" as-child>
                        <Link v-if="generations.links.next" :href="generations.links.next" preserve-scroll>Next</Link>
                        <span v-else>Next</span>
                    </Button>
                </div>
            </nav>
        </div>
    </AppLayout>
</template>
