<script setup lang="ts">
import { computed, ref } from 'vue';
import WeekStrip, { type WeekStripDay } from '../WeekStrip.vue';

// WeekStrip (UI-001) in each VIEW-004 state.
const week: WeekStripDay[] = [
    { id: '2026-10-26', letter: 'M', date: '26', calories: null, isBeforePlan: true },
    { id: '2026-10-27', letter: 'T', date: '27', calories: '2600', isComplete: true, hasTraining: true },
    { id: '2026-10-28', letter: 'W', date: '28', calories: '2100', isComplete: true },
    { id: '2026-10-29', letter: 'T', date: '29', calories: '2600', hasTraining: true, isToday: true },
    { id: '2026-10-30', letter: 'F', date: '30', calories: '2100' },
    { id: '2026-10-31', letter: 'S', date: '31', calories: '2600', hasTraining: true },
    { id: '2026-11-01', letter: 'S', date: '1', calories: '2100' },
];
const selected = ref('2026-10-30');
const days = computed(() => week.map((day) => ({ ...day, isSelected: day.id === selected.value })));
const swipes = ref(0);
</script>

<template>
    <div class="flex max-w-md flex-col gap-6">
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Default</h3>
            <WeekStrip :days="days" @select="selected = $event" @swipe="swipes += $event" />
            <p class="mt-1 text-xs text-secondary-text">Week offset from swipes: {{ swipes }}</p>
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Empty</h3>
            <WeekStrip :days="[]" />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Loading</h3>
            <WeekStrip :days="[]" loading />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Error</h3>
            <WeekStrip :days="days" error="Couldn't load this week. Try again." />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Pending</h3>
            <WeekStrip :days="days" pending />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Disabled</h3>
            <WeekStrip :days="days" disabled />
        </div>
    </div>
</template>
