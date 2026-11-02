<script setup lang="ts">
import type { ProgressShowOutput } from '@/contracts/progress_show';
import ItemCard from '@/design/ItemCard.vue';
import ProgressBar from '@/design/ProgressBar.vue';

// Steps against today's target range (ANA-005).
defineProps<{ steps: NonNullable<ProgressShowOutput['steps']>; pending?: boolean }>();
</script>

<template>
    <ItemCard data-steps :title="steps.title" :pending="pending">
        <template #icon>
            <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="13" cy="4" r="1.75" />
                <path d="M9 21l2.5-6.5L14 16v5M8 12l2-4.5h4l2.5 4M11.5 14.5L13 9" />
            </svg>
        </template>
        <div class="flex flex-col gap-2">
            <ProgressBar :title="steps.bar.title" :label="steps.bar.short_label" :value-text="steps.bar.value_label" :fraction="steps.bar.fraction" />
            <p class="text-sm text-secondary-text">{{ steps.target_label }}</p>
        </div>
    </ItemCard>
</template>
