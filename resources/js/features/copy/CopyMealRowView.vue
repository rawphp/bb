<script setup lang="ts">
import type { CopyShowOutput } from '@/contracts/copy_show';
import PendingMark from '@/design/PendingMark.vue';

// A destination meal (MEAL-060): tick, name, time, targets and what it holds now, or the
// server's note on why it cannot take the copy. The row is the button that picks it.
withDefaults(defineProps<{ row: CopyShowOutput['meals'][number]; selected: boolean; pending?: boolean }>(), { pending: false });
</script>

<template>
    <button
        type="button"
        data-kind="meal"
        :aria-pressed="selected"
        :disabled="!row.enabled"
        class="flex min-h-tap w-full items-center gap-3 rounded-2xl bg-surface p-3 text-left ring-inset active:opacity-70 disabled:opacity-55"
        :class="selected ? 'ring-2 ring-accent' : 'ring-1 ring-secondary-text/25'"
    >
        <svg aria-hidden="true" viewBox="0 0 24 24" class="size-6 shrink-0" :class="selected ? 'text-accent' : 'text-secondary-text'">
            <template v-if="selected">
                <circle cx="12" cy="12" r="10" fill="currentColor" />
                <path d="m7.5 12.5 3 3 6-6.5" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            </template>
            <circle v-else cx="12" cy="12" r="9.5" fill="none" stroke="currentColor" stroke-width="1.75" />
        </svg>
        <span class="min-w-0 flex-1 space-y-0.5">
            <span class="flex items-baseline gap-1.5">
                <span class="font-semibold text-primary-text">{{ row.name }}</span>
                <span v-if="row.time_text" class="text-sm text-secondary-text">{{ row.time_text }}</span>
            </span>
            <span class="block text-sm tabular-nums text-secondary-text" data-test="target">
                <PendingMark v-if="pending" derived>{{ row.target_text }}</PendingMark>
                <template v-else>{{ row.target_text }}</template>
            </span>
            <span class="block text-sm font-semibold" :class="row.note ? 'text-tone-info' : 'text-secondary-text'">{{ row.note ?? row.foods_text }}</span>
        </span>
    </button>
</template>
