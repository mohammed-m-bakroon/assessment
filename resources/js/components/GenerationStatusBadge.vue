<script setup lang="ts">
import type { GenerationStatus } from '@/types/generation';
import { CheckCircle2, Clock, Loader2, XCircle } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ status: GenerationStatus; waitingReason?: string | null }>();

const config = computed(() => {
    switch (props.status) {
        case 'pending':
            return {
                label: props.waitingReason ? 'Waiting for capacity' : 'Submitting',
                icon: Clock,
                spin: false,
                class: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
            };
        case 'queued':
            return { label: 'Queued', icon: Clock, spin: false, class: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300' };
        case 'processing':
            return { label: 'Processing', icon: Loader2, spin: true, class: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300' };
        case 'succeeded':
            return {
                label: 'Succeeded',
                icon: CheckCircle2,
                spin: false,
                class: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
            };
        default:
            return { label: 'Failed', icon: XCircle, spin: false, class: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300' };
    }
});
</script>

<template>
    <span :class="['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium', config.class]">
        <component :is="config.icon" :class="['size-3.5', config.spin && 'animate-spin']" />
        {{ config.label }}
    </span>
</template>
