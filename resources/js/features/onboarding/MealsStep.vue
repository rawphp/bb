<script setup lang="ts">
import { reactive, ref } from 'vue';
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import type { Answers } from './choices';
import StepHeading from './parts/StepHeading.vue';
import StepNote from './parts/StepNote.vue';
import StepPanel from './parts/StepPanel.vue';
import Stepper from './parts/Stepper.vue';
import TimeOfDayPicker from './parts/TimeOfDayPicker.vue';
import WeekdayPicker from './parts/WeekdayPicker.vue';

type Section = OnboardingShowOutput['meals'];
type Period = Section['busy_periods'][number];
type PeriodAnswer = NonNullable<Answers['busy_periods']>[number];
type Weekday = { weekday: number; name: string; full_name: string };

// Step 11: the eating window, the number of meals and busy periods (ONB-014). Busy
// periods are answered as the whole list, built from what the step shows with one period
// changed, added or removed; the server keeps the ones it knows by id. A label is saved
// when it is left. Ported from rp-clone's LifestyleSteps.
const props = defineProps<{ meals: Section; weekdays: Weekday[]; locked?: boolean }>();

const emit = defineEmits<{ answer: [answers: Answers] }>();

// What "Add a busy period" starts with, as rp-clone's addBusyPeriod: noon to 1 PM on weekdays.
const NEW_PERIOD: PeriodAnswer = { label: 'Busy', start_minutes: 720, end_minutes: 780, weekdays: [1, 2, 3, 4, 5] };

function saved(period: Period): PeriodAnswer {
    const days = period.weekdays as number[];
    return { id: period.id, label: period.label, start_minutes: period.start.minutes, end_minutes: period.end.minutes, weekdays: [days[0]!, ...days.slice(1)] };
}

function send(periods: PeriodAnswer[]): void {
    emit('answer', { busy_periods: periods });
}

function change(id: string, edit: Partial<PeriodAnswer>): void {
    send(props.meals.busy_periods.map((period) => (period.id === id ? { ...saved(period), ...edit } : saved(period))));
}

function remove(id: string): void {
    send(props.meals.busy_periods.filter((period) => period.id !== id).map(saved));
}

function add(): void {
    send([...props.meals.busy_periods.map(saved), NEW_PERIOD]);
}

function days(period: Period) {
    const on = period.weekdays as number[];
    return props.weekdays.map((day) => ({ ...day, is_on: on.includes(day.weekday) }));
}

// A period keeps at least one weekday (the answer needs one), so the last one does not switch off.
function toggleDay(period: Period, weekday: number): void {
    const on = period.weekdays as number[];
    const [first, ...rest] = props.weekdays.map((day) => day.weekday).filter((day) => (day === weekday ? !on.includes(day) : on.includes(day)));
    if (first !== undefined) change(period.id, { weekdays: [first, ...rest] });
}

// The label as the field shows it. A re-read replaces it only while nobody is typing, as
// AboutYouStep does for the name, so a refresh landing mid-word does not wipe the word.
const typing = ref<string | null>(null);
const typed = reactive<Record<string, string>>({});

function startTyping(period: Period): void {
    typed[period.id] = period.label;
    typing.value = period.id;
}

function typedLabel(period: Period, event: Event): void {
    const label = (event.target as HTMLInputElement).value;
    if (label !== period.label) change(period.id, { label });
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <StepPanel>
            <TimeOfDayPicker label="First meal" :model-value="meals.first_meal.minutes" :disabled="locked" @update:model-value="(minutes) => emit('answer', { first_meal_minutes: minutes })" />
            <TimeOfDayPicker label="Last meal" :model-value="meals.last_meal.minutes" :disabled="locked" @update:model-value="(minutes) => emit('answer', { last_meal_minutes: minutes })" />
            <StepNote :text="meals.window_note" />
        </StepPanel>
        <StepPanel>
            <Stepper
                label="Meals a day"
                :text="meals.meal_count.text"
                :model-value="meals.meal_count.value"
                :min="meals.meal_count.min"
                :max="meals.meal_count.max"
                :step="meals.meal_count.step"
                :disabled="locked"
                @update:model-value="(count) => emit('answer', { meal_count: count })"
            />
        </StepPanel>
        <StepHeading text="Busy periods" />
        <StepNote :text="meals.busy_note" />
        <StepPanel v-for="period in meals.busy_periods" :key="period.id">
            <div class="flex items-center gap-2">
                <input
                    type="text"
                    maxlength="60"
                    aria-label="Label"
                    placeholder="Label"
                    :value="typing === period.id ? typed[period.id] : period.label"
                    :readonly="locked"
                    class="min-h-tap min-w-0 flex-1 bg-transparent text-sm font-semibold text-primary-text outline-none"
                    @focus="startTyping(period)"
                    @input="typed[period.id] = ($event.target as HTMLInputElement).value"
                    @blur="typing = null"
                    @change="typedLabel(period, $event)"
                />
                <button
                    type="button"
                    :aria-label="`Remove ${period.label}`"
                    :disabled="locked"
                    class="flex size-tap items-center justify-center text-tone-warning active:opacity-70 disabled:opacity-50"
                    @click="remove(period.id)"
                >
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 002 2h6a2 2 0 002-2l1-12M9 7V4h6v3" />
                    </svg>
                </button>
            </div>
            <TimeOfDayPicker label="From" :model-value="period.start.minutes" :disabled="locked" @update:model-value="(minutes) => change(period.id, { start_minutes: minutes })" />
            <TimeOfDayPicker label="To" :model-value="period.end.minutes" :disabled="locked" @update:model-value="(minutes) => change(period.id, { end_minutes: minutes })" />
            <WeekdayPicker :days="days(period)" :disabled="locked" @toggle="(weekday) => toggleDay(period, weekday)" />
        </StepPanel>
        <button
            type="button"
            data-test="add-busy-period"
            :disabled="locked"
            class="flex min-h-tap items-center justify-center gap-2 rounded-xl bg-accent/15 px-4 font-medium text-accent active:opacity-70 disabled:opacity-50"
            @click="add"
        >
            <span aria-hidden="true" class="text-lg">+</span>
            Add a busy period
        </button>
    </div>
</template>
