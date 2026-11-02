<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import type { ScheduleUpdateInput } from '@/contracts/schedule_update';
import type { TodayShowOutput } from '@/contracts/today_show';
import BottomSheet from '@/design/BottomSheet.vue';
import Stepper from '@/features/onboarding/parts/Stepper.vue';
import FormSection from './FormSection.vue';
import ScheduleSheetMessage from './ScheduleSheetMessage.vue';
import SegmentedPicker from './SegmentedPicker.vue';
import SheetBody from './SheetBody.vue';
import TimeField from './TimeField.vue';
import { useDaySheet, type DaySheetProps } from './useDaySheet';
import { useWeekdayChange } from './useWeekdayChange';
import WeekdayChangeSheet from './WeekdayChangeSheet.vue';
import { isoWeekdayOf, weekdayName } from './weekday';

type Schedule = NonNullable<TodayShowOutput['schedule']>;
type ApplyTo = ScheduleUpdateInput['apply_to'];
type Draft = { window_start: string; window_end: string; meal_count: number; busy_periods: { key: number; label: string; start: string; end: string }[]; training_start: string };

// SCH-037: the schedule menu's Edit schedule. The selected day's eating window, meal
// count, busy periods and training time, applied to this day or to all future days; the
// server sends each starting point and offers only all future days on a past day
// (NUT-024). For all future days the weekday can also become a training or a rest day,
// which changes the training weekdays (SCH-039) before the schedule is saved, and asks
// first when the server says the mesocycle must change shape. Ported from rp-clone's EditScheduleSheet.
const props = defineProps<DaySheetProps>();

const emit = defineEmits<{ close: [saved: boolean] }>();

const { data, failure, load, send, busy: saving, error: saveError, seed } = useDaySheet(props);
const weekdays = useWeekdayChange();

const schedule = computed(() => data.value?.schedule ?? null);
const applyTo = ref<ApplyTo>('this_day');
const drafts = reactive<Record<ApplyTo, Draft | null>>({ this_day: null, all_future_days: null });
const iso = ref<number>();
// Whether this weekday trains: as saved, and as the switch now says.
const trained = ref(false);
const trains = ref(false);
// A write of this sheet landed (the weekdays), so closing it must say so, saved or not.
const landed = ref(false);
let nextKey = 0;

function draft(day: Schedule['this_day']): Draft {
    return {
        window_start: day.window_start,
        window_end: day.window_end,
        meal_count: day.meal_count,
        busy_periods: day.busy_periods.map((period) => ({ key: nextKey++, ...period })),
        training_start: day.training_start ?? '',
    };
}

seed((day) => {
    const found = day.schedule;
    if (!found) return;
    applyTo.value = found.apply_to[0]?.value ?? 'all_future_days';
    drafts.this_day = draft(found.this_day);
    drafts.all_future_days = draft(found.all_future_days);
    iso.value = isoWeekdayOf(day.date, found.training_weekdays);
    trained.value = trains.value = found.training_weekdays.some((weekday) => weekday.iso === iso.value && weekday.selected);
});

const current = computed(() => drafts[applyTo.value]);
const weekday = computed(() => (data.value ? weekdayName(data.value.date) : ''));
const future = computed(() => applyTo.value === 'all_future_days');
// This day: its session's time, when it has one. All future days: the weekday's, when it trains.
const showsTrainingTime = computed(() => (future.value ? trains.value : schedule.value?.this_day.training_start != null));
const busy = computed(() => saving.value || weekdays.busy.value);
const message = computed(() => (weekdays.asking.value ? null : (weekdays.error.value ?? saveError.value)));

const canSave = computed(() => {
    const day = current.value;
    if (!day || busy.value) return false;
    const filled = (period: { start: string; end: string }) => period.start !== '' && period.end !== '' && period.start !== period.end;

    // A training time shown must be set: a rest weekday made a training day starts empty.
    return (
        filled({ start: day.window_start, end: day.window_end }) &&
        day.busy_periods.every((period) => period.label.trim() !== '' && filled(period)) &&
        (!showsTrainingTime.value || day.training_start !== '')
    );
});

function addBusyPeriod(): void {
    current.value?.busy_periods.push({ key: nextKey++, label: 'Busy', start: '09:00', end: '10:00' });
}

function removeBusyPeriod(key: number): void {
    const day = current.value;
    if (day) day.busy_periods = day.busy_periods.filter((period) => period.key !== key);
}

async function save(): Promise<void> {
    if (!canSave.value || !schedule.value) return;
    // An earlier refusal of the weekdays says nothing about this save.
    weekdays.cancel();
    if (future.value && trains.value !== trained.value) {
        const changed = schedule.value.training_weekdays.filter((day) => (day.iso === iso.value ? trains.value : day.selected)).map((day) => day.iso);
        if (!(await weekdays.change(changed))) return;
        weekdaysChanged();
    }
    await saveSchedule();
}

async function choose(fewerDays: Parameters<typeof weekdays.choose>[0]): Promise<void> {
    if (!(await weekdays.choose(fewerDays))) return;
    weekdaysChanged();
    await saveSchedule();
}

function weekdaysChanged(): void {
    trained.value = trains.value;
    landed.value = true;
}

async function saveSchedule(): Promise<void> {
    const day = current.value;
    if (!data.value || !day) return;
    const input = {
        date: data.value.date,
        apply_to: applyTo.value,
        window_start: day.window_start,
        window_end: day.window_end,
        meal_count: day.meal_count,
        busy_periods: day.busy_periods.map((period) => ({ label: period.label.trim(), start: period.start, end: period.end })),
        training_start: showsTrainingTime.value && day.training_start !== '' ? day.training_start : null,
    } satisfies ScheduleUpdateInput;
    if ((await send('schedule_update', input, 'The schedule could not be saved.')) === 'done') emit('close', true);
}

const field = 'min-h-tap w-full bg-transparent py-2 text-primary-text placeholder:text-secondary-text/70 focus:outline-none';
</script>

<template>
    <BottomSheet
        :open="true"
        title="Edit schedule"
        :save-disabled="!canSave"
        :cancel-disabled="busy"
        :dismissible="!busy"
        :pending="pending"
        @cancel="emit('close', landed)"
        @save="save"
    >
        <SheetBody :loaded="!!data" :failure="failure" what="This day" @retry="load">
            <template v-if="data && schedule && current">
                <FormSection
                    :header="data.title"
                    :footer="
                        future
                            ? `Applies from tomorrow. The eating window and meals change every day. A busy period you edit or remove changes on every day it repeats. Training time and new busy periods apply to ${weekday}s only.`
                            : 'Only this day changes. Your schedule stays as it is.'
                    "
                >
                    <div class="py-2">
                        <SegmentedPicker v-model="applyTo" label="Apply to" :options="schedule.apply_to" :disabled="busy" />
                    </div>
                </FormSection>
                <FormSection header="Eating">
                    <TimeField v-model="current.window_start" label="Window starts" :disabled="busy" />
                    <TimeField v-model="current.window_end" label="Window ends" :disabled="busy" />
                    <Stepper v-model="current.meal_count" label="Meals a day" :text="`Meals a day: ${current.meal_count}`" :min="2" :max="6" :step="1" :disabled="busy" />
                </FormSection>
                <FormSection v-if="future || showsTrainingTime" header="Training">
                    <label v-if="future && iso !== undefined" class="flex min-h-tap items-center justify-between gap-3 py-1">
                        <span class="text-primary-text">Train on {{ weekday }}s</span>
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="trains"
                            :disabled="busy"
                            class="relative h-8 w-13 shrink-0 rounded-full transition-colors disabled:opacity-50"
                            :class="trains ? 'bg-tone-good' : 'bg-secondary-text/30'"
                            @click="trains = !trains"
                        >
                            <span class="absolute top-0.5 left-0.5 size-7 rounded-full bg-white shadow transition-transform" :class="trains ? 'translate-x-5' : ''" />
                        </button>
                    </label>
                    <TimeField v-if="showsTrainingTime" v-model="current.training_start" label="Starts" :disabled="busy" />
                </FormSection>
                <FormSection header="Busy periods">
                    <div v-for="period in current.busy_periods" :key="period.key" class="py-1">
                        <div class="flex items-center gap-2">
                            <input v-model="period.label" type="text" aria-label="Busy period label" placeholder="Label" :disabled="busy" :class="field" />
                            <button
                                type="button"
                                aria-label="Remove busy period"
                                :disabled="busy"
                                class="flex size-tap shrink-0 items-center justify-center text-tone-warning disabled:opacity-50"
                                @click="removeBusyPeriod(period.key)"
                            >
                                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round">
                                    <path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" />
                                </svg>
                            </button>
                        </div>
                        <TimeField v-model="period.start" label="From" :disabled="busy" />
                        <TimeField v-model="period.end" label="To" :disabled="busy" />
                    </div>
                    <button type="button" data-test="add-busy" :disabled="busy" class="flex min-h-tap w-full items-center gap-2 text-accent disabled:opacity-50" @click="addBusyPeriod">
                        <span aria-hidden="true" class="text-xl leading-none">+</span>
                        Add busy period
                    </button>
                </FormSection>
                <ScheduleSheetMessage :text="message" />
            </template>
            <p v-else-if="data" class="px-4 text-secondary-text">There is no schedule to change yet.</p>
        </SheetBody>
        <WeekdayChangeSheet
            :open="weekdays.asking.value !== null"
            :busy="busy"
            :error="weekdays.error.value"
            :question="weekdays.question.value"
            @choose="choose" @cancel="weekdays.cancel" />
    </BottomSheet>
</template>
