<script setup lang="ts">
import type { DayRedistributeShowOutput } from '@/contracts/day_redistribute_show';
import PendingMark from '@/design/PendingMark.vue';

// One later meal on the Redistribute sheet (DAY-002): tap to take it in or leave it out.
// What it would hold is the server's (OFF-008 dims it while a change waits to sync).
defineProps<{ row: DayRedistributeShowOutput['rows'][number]; pending?: boolean }>();
defineEmits<{ toggle: [] }>();
</script>

<template>
    <button
        type="button"
        class="flex min-h-tap w-full items-center gap-3 rounded-xl bg-background p-3 text-left active:opacity-70"
        :aria-pressed="row.selected"
        data-test="redistribute-row"
        @click="$emit('toggle')"
    >
        <svg v-if="row.selected" aria-hidden="true" viewBox="0 0 24 24" class="size-7 shrink-0 text-accent" fill="currentColor">
            <path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm-1.2 14.2-4-4 1.4-1.4 2.6 2.6 5.6-5.6 1.4 1.4z" />
        </svg>
        <svg v-else aria-hidden="true" viewBox="0 0 24 24" class="size-7 shrink-0 text-secondary-text" fill="none" stroke="currentColor" stroke-width="1.5">
            <circle cx="12" cy="12" r="9.5" />
        </svg>
        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
            <span class="font-semibold text-primary-text">{{ row.name }}</span>
            <span v-if="row.time_text" class="text-sm text-secondary-text">{{ row.time_text }}</span>
        </span>
        <span class="flex flex-col items-end gap-0.5 text-sm font-medium tabular-nums">
            <PendingMark v-if="pending" derived>
                <span class="flex flex-col items-end gap-0.5">
                    <span class="text-macro-calories">{{ row.kcal_text }}</span>
                    <span class="text-macro-protein">{{ row.protein_text }}</span>
                </span>
            </PendingMark>
            <template v-else>
                <span class="text-macro-calories">{{ row.kcal_text }}</span>
                <span class="text-macro-protein">{{ row.protein_text }}</span>
            </template>
        </span>
    </button>
</template>
