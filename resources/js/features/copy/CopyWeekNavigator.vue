<script setup lang="ts">
import { computed } from 'vue';
import type { CopyShowOutput } from '@/contracts/copy_show';
import WeekStrip from '@/design/WeekStrip.vue';

// The shown week's strip with an arrow each side (MEAL-060, SCH-034). `selected` names the
// days the strip marks: the shown day when copying foods, the days picked when copying a day.
const props = withDefaults(
    defineProps<{ strip: CopyShowOutput['strip']; selected: string[]; hasPrevious: boolean; hasNext: boolean; pending?: boolean; disabled?: boolean }>(),
    { pending: false, disabled: false },
);
const emit = defineEmits<{ select: [date: string]; week: [step: -1 | 1] }>();

// "19 Sep", as rp-clone's navigator reads (en-GB would say "Sept").
const day = new Intl.DateTimeFormat('en-US', { day: 'numeric', timeZone: 'UTC' });
const month = new Intl.DateTimeFormat('en-US', { month: 'short', timeZone: 'UTC' });
const dayMonth = { format: (date: Date) => `${day.format(date)} ${month.format(date)}` };
const range = computed(() => {
    const first = props.strip[0];
    const last = props.strip.at(-1);
    return first && last ? `${dayMonth.format(new Date(first.date))} – ${dayMonth.format(new Date(last.date))}` : '';
});
const days = computed(() => props.strip.map((day) => ({ ...day, is_selected: props.selected.includes(day.date), is_complete: false })));

const arrow = 'flex min-h-tap min-w-tap items-center justify-center text-accent active:opacity-70 disabled:opacity-40';
</script>

<template>
    <div class="space-y-1.5">
        <div class="flex items-center">
            <button type="button" :class="arrow" aria-label="Previous week" :disabled="!hasPrevious || disabled" @click="emit('week', -1)">
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 5-7 7 7 7" /></svg>
            </button>
            <p class="flex-1 text-center text-sm font-semibold text-secondary-text" data-test="range">{{ range }}</p>
            <button type="button" :class="arrow" aria-label="Next week" :disabled="!hasNext || disabled" @click="emit('week', 1)">
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 5 7 7-7 7" /></svg>
            </button>
        </div>
        <WeekStrip
            :days="days"
            label="Copy to"
            :pending="pending"
            :disabled="disabled"
            @select="emit('select', $event)"
            @swipe="(step) => (step === -1 ? hasPrevious : hasNext) && emit('week', step)"
        />
    </div>
</template>
