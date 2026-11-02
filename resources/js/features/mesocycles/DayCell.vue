<script setup lang="ts">
import { computed } from 'vue';
import type { MesocycleDay } from './types';

// One day in a week's column; tapping it opens that workout (MESO-040, NAV-002).
// A day an early end dropped (MESO-043) can't open.
const props = withDefaults(defineProps<{ day: MesocycleDay; to: string; pending?: boolean }>(), { pending: false });

const STATE_TEXT = {
    done: 'Done',
    started: 'Started',
    skipped: 'Skipped',
    missed: 'Missed',
    not_planned: 'Not planned',
    upcoming: null,
} as const;

// SF Symbols in the Swift cell: checkmark.circle.fill, circle.lefthalf.filled, forward.end, minus.circle, nosign.
const ICONS = {
    done: 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm-1.4 14.2-4.1-4.1 1.4-1.4 2.7 2.7 5.5-5.5 1.4 1.4-6.9 6.9Z',
    started: 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 2v16a8 8 0 0 1 0-16Z',
    skipped: 'M5 5l9 7-9 7V5Zm11 0h2v14h-2V5Z',
    missed: 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 2a8 8 0 1 1 0 16 8 8 0 0 1 0-16Zm-5 7v2h10v-2H7Z',
    not_planned: 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 2a8 8 0 0 1 6.3 12.9L7.1 5.7A8 8 0 0 1 12 4ZM5.7 7.1l11.2 11.2A8 8 0 0 1 5.7 7.1Z',
    upcoming: null,
} as const;

const icon = computed(() => ICONS[props.day.state]);
const stateText = computed(() => STATE_TEXT[props.day.state]);
</script>

<template>
    <component
        :is="day.can_open ? 'RouterLink' : 'div'"
        :to="day.can_open ? to : undefined"
        class="flex min-h-tap w-full flex-col gap-1 rounded-[10px] bg-background p-2.5 text-left"
        :class="{ 'opacity-50': day.state === 'not_planned' }"
        :aria-disabled="day.can_open ? undefined : 'true'"
        :title="day.can_open ? 'Opens this workout' : 'Not planned'"
        data-test="day"
    >
        <span class="flex items-center gap-1.5">
            <span class="min-w-0 flex-1 truncate font-semibold text-primary-text">{{ day.title }}</span>
            <svg v-if="icon" viewBox="0 0 24 24" class="size-4 shrink-0 text-secondary-text" fill="currentColor" aria-hidden="true">
                <path :d="icon" fill-rule="evenodd" />
            </svg>
            <span v-if="stateText" class="sr-only">{{ stateText }}</span>
        </span>
        <span v-if="!day.exercises.length" class="text-sm text-secondary-text">No exercises yet</span>
        <span v-for="line in day.exercises" :key="line.slot_id" class="flex items-baseline gap-1 text-sm">
            <span class="min-w-0 flex-1 truncate text-primary-text">{{ line.name }}</span>
            <span class="tabular-nums text-secondary-text" :class="{ 'opacity-50': pending }">{{ line.sets }}</span>
        </span>
    </component>
</template>
