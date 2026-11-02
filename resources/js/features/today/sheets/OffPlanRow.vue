<script setup lang="ts">
import type { TodayShowOutput } from '@/contracts/today_show';
import ItemCard from '@/design/ItemCard.vue';
import PendingMark from '@/design/PendingMark.vue';

// DAY-001: an off-plan entry of the day, with no meal slot and no check-in box. While it
// waits to sync it shows as typed, with a pending mark (OFF-009).
defineProps<{ entry: TodayShowOutput['off_plan'][number]; pending?: boolean }>();
</script>

<template>
    <ItemCard :title="entry.name" :time="entry.time_text ?? undefined">
        <template #icon>
            <svg viewBox="0 0 24 24" class="size-7" fill="none" stroke="currentColor" stroke-width="1.5">
                <circle cx="12" cy="12" r="9.5" />
                <path d="M9 7v4a1.5 1.5 0 0 0 3 0V7m-1.5 0v10M15 7c-1 0-1.5 1.5-1.5 3.5S14 13 15 13v4" stroke-linecap="round" />
            </svg>
        </template>
        <p class="flex flex-wrap items-baseline gap-x-2">
            <span class="sr-only">{{ entry.macros.label }}</span>
            <span class="flex gap-2 text-sm font-medium tabular-nums" aria-hidden="true" data-test="macros">
                <span class="text-macro-calories">{{ entry.macros.kcal }}</span>
                <span class="text-macro-protein">{{ entry.macros.protein }}</span>
                <span class="text-macro-fat">{{ entry.macros.fat }}</span>
                <span class="text-macro-carbs">{{ entry.macros.carbs }}</span>
            </span>
            <PendingMark v-if="pending" />
        </p>
    </ItemCard>
</template>
