<script setup lang="ts">
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import type { Answers } from './choices';
import StepHeading from './parts/StepHeading.vue';
import StepNote from './parts/StepNote.vue';
import StepPanel from './parts/StepPanel.vue';
import TimeOfDayPicker from './parts/TimeOfDayPicker.vue';
import WeekdayPicker from './parts/WeekdayPicker.vue';

type Section = OnboardingShowOutput['weigh_ins'];
type Day = Section['days'][number];
type DayAnswer = NonNullable<Answers['weigh_ins']>[number];
type ReviewDay = NonNullable<Answers['review_weekday']>;

// Step 12: weigh-in days with a time each (ONB-015), and the weekly review day
// (ONB-016). The weigh-ins are answered as the whole list of days, built from what the
// step shows with one day changed; a day just switched on sends no time, so the server
// gives it its default. The server says when there are too few to continue. Ported from
// rp-clone's LifestyleSteps.
const props = defineProps<{ weighIns: Section; locked?: boolean }>();

const emit = defineEmits<{ answer: [answers: Answers] }>();

function send(change: (day: Day) => DayAnswer | null): void {
    const days = props.weighIns.days.map(change).filter((day): day is DayAnswer => day !== null);
    emit('answer', { weigh_ins: days });
}

function toggle(weekday: number): void {
    send((day) => {
        if (day.weekday !== weekday) return day.is_on ? { weekday: day.weekday, minutes: day.time.minutes } : null;
        return day.is_on ? null : { weekday };
    });
}

function setTime(weekday: number, minutes: number): void {
    send((day) => (day.is_on ? { weekday: day.weekday, minutes: day.weekday === weekday ? minutes : day.time.minutes } : null));
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <StepHeading text="Weigh-in days" />
        <WeekdayPicker :days="weighIns.days" :disabled="locked" @toggle="toggle" />
        <template v-for="day in weighIns.days" :key="day.weekday">
            <StepPanel v-if="day.is_on">
                <TimeOfDayPicker :label="day.full_name" :model-value="day.time.minutes" :disabled="locked" @update:model-value="(minutes) => setTime(day.weekday, minutes)" />
            </StepPanel>
        </template>
        <StepNote v-if="weighIns.hint" :text="weighIns.hint" />
        <StepNote :text="weighIns.time_note" />
        <StepHeading text="Weekly review day" />
        <StepNote :text="weighIns.review_note" />
        <div role="radiogroup" aria-label="Weekly review day" class="flex gap-1">
            <button
                v-for="day in weighIns.review_days"
                :key="day.value"
                type="button"
                role="radio"
                :aria-checked="day.is_selected"
                :disabled="locked || !day.is_enabled"
                class="flex min-h-tap flex-1 items-center justify-center rounded-lg text-sm font-semibold disabled:opacity-50"
                :class="day.is_selected ? 'bg-accent text-on-accent' : 'bg-background text-primary-text'"
                @click="day.is_selected || emit('answer', { review_weekday: day.value as ReviewDay })"
            >
                {{ day.title }}
            </button>
        </div>
    </div>
</template>
