<script setup lang="ts">
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import type { Answers } from './choices';
import StepNote from './parts/StepNote.vue';
import StepPanel from './parts/StepPanel.vue';
import Stepper from './parts/Stepper.vue';
import TimeOfDayPicker from './parts/TimeOfDayPicker.vue';
import WeekdayPicker from './parts/WeekdayPicker.vue';

type Section = OnboardingShowOutput['training_days'];
type Day = Section['days'][number];
type DayAnswer = NonNullable<Answers['training_days']>[number];

// Step 8: the weekdays the user trains, and a start time and length for each (ONB-011).
// The answer is the whole list of training days, built from what the step shows, with
// one day changed; a day just switched on sends no time or length, so the server gives
// it its default. Ported from rp-clone's TrainingSteps.
const props = defineProps<{ trainingDays: Section; locked?: boolean }>();

const emit = defineEmits<{ answer: [answers: Answers] }>();

function saved(day: Day): DayAnswer {
    return { weekday: day.weekday, start_minutes: day.start.minutes, length_minutes: day.length_minutes };
}

function send(change: (day: Day) => DayAnswer | null): void {
    const days = props.trainingDays.days.map(change).filter((day): day is DayAnswer => day !== null);
    emit('answer', { training_days: days });
}

function toggle(weekday: number): void {
    send((day) => {
        if (day.weekday !== weekday) return day.is_on ? saved(day) : null;
        return day.is_on ? null : { weekday };
    });
}

function setDay(weekday: number, change: Partial<DayAnswer>): void {
    send((day) => (day.is_on ? { ...saved(day), ...(day.weekday === weekday ? change : {}) } : null));
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <StepNote :text="trainingDays.help" />
        <WeekdayPicker :days="trainingDays.days" :disabled="locked" @toggle="toggle" />
        <template v-for="day in trainingDays.days" :key="day.weekday">
            <StepPanel v-if="day.is_on">
                <span class="text-sm font-semibold text-primary-text">{{ day.full_name }}</span>
                <TimeOfDayPicker label="Start time" :model-value="day.start.minutes" :disabled="locked" @update:model-value="(minutes) => setDay(day.weekday, { start_minutes: minutes })" />
                <Stepper
                    :label="`${day.full_name} length`"
                    :text="day.length_text"
                    :model-value="day.length_minutes"
                    :min="trainingDays.length_min"
                    :max="trainingDays.length_max"
                    :step="trainingDays.length_step"
                    :disabled="locked"
                    @update:model-value="(minutes) => setDay(day.weekday, { length_minutes: minutes })"
                />
            </StepPanel>
        </template>
    </div>
</template>
