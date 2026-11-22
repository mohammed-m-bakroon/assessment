<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatUsd, formatUsdRange } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import type { Estimate } from '@/types/generation';
import { Head, useForm } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { ImagePlus, Loader2, Sparkles, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    options: {
        sizes: string[];
        qualities: string[];
        max_prompt_length: number;
        max_reference_images: number;
        markup_percent: number;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Generate', href: '/generate' }];

const sizeLabels: Record<string, string> = {
    '1024x1024': 'Square',
    '1536x1024': 'Landscape',
    '1024x1536': 'Portrait',
};

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
});

// Count code points like the server's mb_strlen(), not UTF-16 units.
const promptLength = computed(() => [...form.prompt].length);
const referenceCount = computed(() => form.reference_images.filter((url) => url.trim() !== '').length);

const estimate = ref<Estimate | null>(null);
const estimating = ref(false);
const estimateError = ref(false);

async function refreshEstimate() {
    estimating.value = true;
    estimateError.value = false;
    try {
        const url = route('generations.estimate', {
            prompt_length: promptLength.value,
            size: form.size,
            quality: form.quality,
            reference_images: referenceCount.value,
        });
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(String(response.status));
        estimate.value = await response.json();
    } catch {
        estimateError.value = true;
    } finally {
        estimating.value = false;
    }
}

watchDebounced([promptLength, () => form.size, () => form.quality, referenceCount], refreshEstimate, { debounce: 250, immediate: true });

const tokenRows = computed(() => {
    if (!estimate.value) return [];
    const { tokens_min: min, tokens_max: max } = estimate.value;
    const range = (a: number, b: number) => (a === b ? a.toLocaleString() : `${a.toLocaleString()}–${b.toLocaleString()}`);
    return [
        { label: 'Prompt text', value: range(min.input_text_tokens, max.input_text_tokens), note: '±15% until generated' },
        { label: 'Reference images', value: range(min.input_image_tokens, max.input_image_tokens), note: '765 each' },
        { label: 'Image output', value: range(min.output_tokens, max.output_tokens), note: 'exact' },
        { label: 'Reasoning', value: range(min.reasoning_tokens, max.reasoning_tokens), note: 'known after generating' },
    ];
});

function addReference() {
    if (form.reference_images.length < props.options.max_reference_images) form.reference_images.push('');
}

function removeReference(index: number) {
    form.reference_images.splice(index, 1);
}

function referenceError(index: number): string | undefined {
    return (form.errors as Record<string, string>)[`reference_images.${index}`];
}

function submit() {
    form.post(route('generations.store'));
}
</script>

<template>
    <Head title="Generate" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="grid gap-6 p-4 lg:grid-cols-[minmax(0,1fr)_340px] lg:p-6">
            <form class="space-y-6" @submit.prevent="submit">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight">Generate an image</h1>
                    <p class="text-sm text-muted-foreground">Describe what you want. You'll see the price range before you submit.</p>
                </div>

                <div class="grid gap-2">
                    <div class="flex items-baseline justify-between">
                        <Label for="prompt">Prompt</Label>
                        <span class="text-xs tabular-nums text-muted-foreground">{{ promptLength.toLocaleString() }} / {{ options.max_prompt_length.toLocaleString() }}</span>
                    </div>
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        rows="5"
                        :maxlength="options.max_prompt_length"
                        required
                        placeholder="A lighthouse on a cliff at dawn, oil painting"
                        class="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                    />
                    <InputError :message="form.errors.prompt" />
                </div>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Size</legend>
                    <div class="grid grid-cols-3 gap-2">
                        <label
                            v-for="size in options.sizes"
                            :key="size"
                            :class="[
                                'flex cursor-pointer flex-col items-center gap-1 rounded-lg border p-3 text-sm transition-colors',
                                form.size === size ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'hover:bg-accent',
                            ]"
                        >
                            <input v-model="form.size" type="radio" name="size" :value="size" class="sr-only" />
                            <span class="font-medium">{{ sizeLabels[size] ?? size }}</span>
                            <span class="text-xs text-muted-foreground">{{ size.replace('x', ' × ') }}</span>
                        </label>
                    </div>
                    <InputError :message="form.errors.size" />
                </fieldset>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Quality</legend>
                    <div class="grid grid-cols-3 gap-2">
                        <label
                            v-for="quality in options.qualities"
                            :key="quality"
                            :class="[
                                'cursor-pointer rounded-lg border p-3 text-center text-sm font-medium capitalize transition-colors',
                                form.quality === quality ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'hover:bg-accent',
                            ]"
                        >
                            <input v-model="form.quality" type="radio" name="quality" :value="quality" class="sr-only" />
                            {{ quality }}
                        </label>
                    </div>
                    <InputError :message="form.errors.quality" />
                </fieldset>

                <div class="grid gap-2">
                    <div class="flex items-baseline justify-between">
                        <Label>Reference images <span class="font-normal text-muted-foreground">(optional)</span></Label>
                        <span class="text-xs text-muted-foreground">{{ form.reference_images.length }} / {{ options.max_reference_images }}</span>
                    </div>
                    <div v-for="(_, index) in form.reference_images" :key="index" class="grid gap-1">
                        <div class="flex gap-2">
                            <Input v-model="form.reference_images[index]" type="url" placeholder="https://example.com/style.png" />
                            <Button type="button" variant="ghost" size="icon" :aria-label="`Remove reference image ${index + 1}`" @click="removeReference(index)">
                                <X />
                            </Button>
                        </div>
                        <InputError :message="referenceError(index)" />
                    </div>
                    <InputError :message="form.errors.reference_images" />
                    <div>
                        <Button
                            v-if="form.reference_images.length < options.max_reference_images"
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="addReference"
                        >
                            <ImagePlus /> Add reference image
                        </Button>
                    </div>
                </div>

                <Button type="submit" size="lg" class="w-full sm:w-auto" :disabled="form.processing || form.prompt.trim() === ''">
                    <Loader2 v-if="form.processing" class="animate-spin" />
                    <Sparkles v-else />
                    Generate
                    <span v-if="estimate" class="font-normal opacity-80">· up to {{ formatUsd(estimate.charge_max) }}</span>
                </Button>
            </form>

            <aside class="lg:sticky lg:top-4 lg:self-start">
                <Card>
                    <CardHeader>
                        <CardDescription>Estimated cost</CardDescription>
                        <CardTitle class="flex items-center gap-2 text-2xl tabular-nums">
                            <template v-if="estimate">{{ formatUsdRange(estimate.charge_min, estimate.charge_max) }}</template>
                            <template v-else-if="estimateError">Unavailable</template>
                            <template v-else>…</template>
                            <Loader2 v-if="estimating" class="size-4 animate-spin text-muted-foreground" />
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-4 text-sm">
                        <p class="text-muted-foreground">
                            You pay exactly what the generation costs, plus {{ options.markup_percent }}%. Part of the cost (reasoning) is only known once the
                            image is ready, so the final price lands somewhere in this range.
                        </p>
                        <p class="font-medium text-emerald-700 dark:text-emerald-400">Failed generations are free.</p>

                        <table v-if="estimate" class="w-full text-xs">
                            <caption class="mb-1 text-left font-medium text-foreground">Tokens</caption>
                            <tbody>
                                <tr v-for="row in tokenRows" :key="row.label" class="border-t">
                                    <td class="py-1.5">
                                        {{ row.label }}
                                        <div class="text-muted-foreground">{{ row.note }}</div>
                                    </td>
                                    <td class="py-1.5 text-right tabular-nums">{{ row.value }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </aside>
        </div>
    </AppLayout>
</template>
