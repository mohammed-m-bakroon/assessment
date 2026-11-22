<script setup lang="ts">
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { Sparkles } from 'lucide-vue-next';

interface Props {
    class?: string;
}

defineProps<Props>();

const page = usePage<SharedData>();
</script>

<template>
    <div class="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
        <Sparkles class="size-5" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate font-semibold leading-none">{{ page.props.name }}</span>
    </div>
</template>
