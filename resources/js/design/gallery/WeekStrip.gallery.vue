<script setup lang="ts">
import { computed, ref } from 'vue';
import WeekStrip, { type WeekStripDay } from '../WeekStrip.vue';

// WeekStrip (UI-001) in each VIEW-004 state.
const week: WeekStripDay[] = [
    { date: '2026-10-26', letter: 'M', number: '26', calories_text: '–', is_today: false, is_selected: false, is_complete: false, has_training: false, is_before_plan: true },
    { date: '2026-10-27', letter: 'T', number: '27', calories_text: '2600', is_today: false, is_selected: false, is_complete: true, has_training: true, is_before_plan: false },
    { date: '2026-10-28', letter: 'W', number: '28', calories_text: '2100', is_today: false, is_selected: false, is_complete: true, has_training: false, is_before_plan: false },
    { date: '2026-10-29', letter: 'T', number: '29', calories_text: '2600', is_today: true, is_selected: false, is_complete: false, has_training: true, is_before_plan: false },
    { date: '2026-10-30', letter: 'F', number: '30', calories_text: '2100', is_today: false, is_selected: false, is_complete: false, has_training: false, is_before_plan: false },
    { date: '2026-10-31', letter: 'S', number: '31', calories_text: '2600', is_today: false, is_selected: false, is_complete: false, has_training: true, is_before_plan: false },
    { date: '2026-11-01', letter: 'S', number: '1', calories_text: '2100', is_today: false, is_selected: false, is_complete: false, has_training: false, is_before_plan: false },
];
const selected = ref('2026-10-30');
const days = computed(() => week.map((day) => ({ ...day, is_selected: day.date === selected.value })));
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
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Read-only</h3>
            <WeekStrip :days="days" readonly />
        </div>
    </div>
</template>
