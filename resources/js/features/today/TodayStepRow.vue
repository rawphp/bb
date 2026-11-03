<script setup lang="ts">
import type { TodayShowOutput } from '@/contracts/today_show';

// SCH-011: the day's step target, with the actual steps when there are any. Ported from
// rp-clone's TodayStepRow.
defineProps<{ steps: NonNullable<TodayShowOutput['steps']> }>();
</script>

<template>
    <p data-test="steps" class="flex items-center gap-2 px-1 text-sm text-secondary-text">
        <svg viewBox="0 0 24 24" class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true">
            <circle cx="13" cy="4" r="1.75" />
            <path d="M8 21l2.5-5.5L13 18v3M10.5 15.5l1-5 3 2.5 2.5.5M8 11l3.5-1.5" />
        </svg>
        <span>{{ steps.target_text }}<template v-if="steps.actual_text"> · {{ steps.actual_text }}</template></span>
    </p>
</template>
