<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import { useWrite } from '@/app/useWrite';
import type { ScheduleDefaultsUpdateInput } from '@/contracts/schedule_defaults_update';
import type { SettingsShowOutput } from '@/contracts/settings_show';
import { toneClasses } from '@/design/tones';
import Stepper from '@/features/onboarding/parts/Stepper.vue';
import WeekdayPicker from '@/features/onboarding/parts/WeekdayPicker.vue';
import StepRangeRow from './StepRangeRow.vue';
import SwitchRow from './SwitchRow.vue';

// Settings → Schedule defaults (SET-005): the eating window, meals a day, training days
// and times, busy periods, the step plan and the review day. Weigh-in days are not on
// this screen (rp-clone gives them their own, which the weigh-ins screens bring). The form starts from what settings_show holds, with the bounds, steps and
// defaults it sends, and Save sends it whole (schedule_defaults_update); the server
// recalculates the targets from the next day and says what it did in `notice`. The form
// follows each new answer (a refresh of the cached copy, say) until the user changes something.
const props = defineProps<{ payload?: SettingsShowOutput }>();

const { data: settings, failure, load } = useRead<SettingsShowOutput>('settings_show', {}, 'Your schedule could not be loaded.', props.payload);
const { send, busy, error } = useWrite(load);

type Weekdays = ScheduleDefaultsUpdateInput['busy_periods'][number]['weekdays'];
type Session = { start: string; minutes: number };
type Busy = { key: number; label: string; start: string; end: string; days: { weekday: number; on: boolean }[] };
type Draft = {
    eating_window_start: string;
    eating_window_end: string;
    meal_count: number;
    training: Record<number, Session | null>;
    busy: Busy[];
    steps: Record<number, { min: string; max: string }>;
    review_weekday: number | null;
};

const draft = ref<Draft | null>(null);
// The draft as last filled from the server, to tell whether the user has changed it.
let seeded = '';
let keys = 0;

function busyFrom(period: SettingsShowOutput['schedule']['new_busy_period']): Busy {
    return { key: keys++, label: period.label, start: period.start, end: period.end, days: period.days.map(({ weekday, on }) => ({ weekday, on })) };
}

function seed(from: SettingsShowOutput): void {
    const schedule = from.schedule;
    const text = (value: number | null | undefined) => (value === null || value === undefined ? '' : String(value));
    draft.value = {
        eating_window_start: schedule.eating_window_start,
        eating_window_end: schedule.eating_window_end,
        meal_count: schedule.meal_count,
        training: Object.fromEntries(schedule.weekdays.map(({ weekday }) => {
            const day = schedule.training_days.find((training) => training.weekday === weekday);
            return [weekday, day ? { start: day.start, minutes: day.minutes } : null];
        })),
        busy: schedule.busy_periods.map(busyFrom),
        steps: Object.fromEntries(schedule.step_plan.map((day) => [day.weekday, { min: text(day.min), max: text(day.max) }])),
        review_weekday: schedule.review_weekday ?? null,
    };
    seeded = JSON.stringify(draft.value);
}

const edited = () => draft.value !== null && JSON.stringify(draft.value) !== seeded;

watch(settings, (now) => now && !edited() && seed(now), { immediate: true });

const schedule = computed(() => settings.value?.schedule);

function toggleTraining(weekday: number, on: boolean): void {
    if (!draft.value || !schedule.value) return;
    draft.value.training[weekday] = on ? { start: schedule.value.new_training_day_start, minutes: schedule.value.new_training_day_minutes } : null;
}

function pickerDays(period: Busy) {
    return period.days.map((day) => {
        const named = schedule.value?.weekdays.find((weekday) => weekday.weekday === day.weekday);
        return { weekday: day.weekday, name: named?.short_label ?? '', full_name: named?.label ?? '', is_on: day.on };
    });
}

function toggleBusyDay(period: Busy, weekday: number): void {
    const day = period.days.find((entry) => entry.weekday === weekday);
    if (day) day.on = !day.on;
}

const filled = (text: string) => text.trim() !== '';
const whole = (text: string) => /^\d+$/.test(text.trim());
const steps = computed(() => Object.values(draft.value?.steps ?? {}));
// A day's range is both numbers or neither, each typed as digits only, so "8.000" never
// goes out as 8. The server judges the numbers themselves.
const halfRange = computed(() => steps.value.some((day) => filled(day.min) !== filled(day.max)));
const notWhole = computed(() => steps.value.some((day) => [day.min, day.max].some((text) => filled(text) && !whole(text))));
const held = computed(() => halfRange.value || notWhole.value);

function input(form: Draft, weekdays: number[]): ScheduleDefaultsUpdateInput {
    return {
        eating_window_start: form.eating_window_start,
        eating_window_end: form.eating_window_end,
        meal_count: form.meal_count,
        training_days: weekdays.flatMap((weekday) => {
            const session = form.training[weekday];
            return session ? [{ weekday, start: session.start, minutes: session.minutes }] : [];
        }),
        busy_periods: form.busy.map((period) => ({
            label: period.label.trim(),
            start: period.start,
            end: period.end,
            // A period with no day ticked goes as it is, for the server to refuse in its own words.
            weekdays: period.days.filter((day) => day.on).map((day) => ({ weekday: day.weekday })) as Weekdays,
        })),
        step_plan: weekdays.flatMap((weekday) => {
            const day = form.steps[weekday];
            return day && filled(day.min) && filled(day.max) ? [{ weekday, min: Number(day.min.trim()), max: Number(day.max.trim()) }] : [];
        }),
        review_weekday: form.review_weekday,
    };
}

async function save(): Promise<void> {
    if (!draft.value || !schedule.value || held.value) return;
    const outcome = await send('schedule_defaults_update', { ...input(draft.value, schedule.value.weekdays.map((day) => day.weekday)) }, 'Your schedule could not be saved. Try again.');
    if (outcome === 'done' && settings.value) seed(settings.value);
}

const card = 'divide-y divide-secondary-text/20 overflow-hidden rounded-2xl bg-surface';
const heading = 'px-4 pb-2 text-sm font-semibold text-secondary-text';
const footer = 'px-4 pt-2 text-sm text-secondary-text';
const row = 'flex min-h-tap items-center justify-between gap-3 px-4 py-2';
const time = 'rounded-lg bg-background px-3 py-1.5 tabular-nums text-primary-text disabled:opacity-60';
</script>

<template>
    <main class="bb-screen-content space-y-4">
        <header class="grid grid-cols-[1fr_auto_1fr] items-center gap-2">
            <span />
            <h1 class="text-center text-lg font-semibold">Schedule defaults</h1>
            <button
                v-if="draft"
                type="button"
                data-test="save"
                class="min-h-tap justify-self-end font-semibold text-accent disabled:opacity-40"
                :disabled="busy || held"
                :aria-busy="busy"
                @click="save"
            >
                {{ busy ? 'Saving…' : 'Save' }}
            </button>
        </header>

        <template v-if="settings && schedule && draft">
            <p v-if="settings.notice" class="rounded-2xl p-4 text-sm" :class="toneClasses[settings.notice.tone]" data-test="notice">{{ settings.notice.text }}</p>
            <p v-if="error" class="rounded-2xl p-4 text-sm" :class="toneClasses.warning" role="alert">{{ error }}</p>

            <section aria-labelledby="schedule-eating">
                <h2 id="schedule-eating" :class="heading">Eating</h2>
                <div :class="card">
                    <label :class="row">
                        <span>Window starts</span>
                        <input v-model="draft.eating_window_start" data-test="window-start" type="time" :disabled="busy" :class="time" />
                    </label>
                    <label :class="row">
                        <span>Window ends</span>
                        <input v-model="draft.eating_window_end" data-test="window-end" type="time" :disabled="busy" :class="time" />
                    </label>
                    <div class="px-4 py-2">
                        <Stepper
                            v-model="draft.meal_count"
                            label="Meals a day"
                            :text="`Meals a day: ${draft.meal_count}`"
                            :min="schedule.meal_count_min"
                            :max="schedule.meal_count_max"
                            :step="1"
                            :disabled="busy"
                        />
                    </div>
                </div>
            </section>

            <section aria-labelledby="schedule-training">
                <h2 id="schedule-training" :class="heading">Training days</h2>
                <div :class="card">
                    <template v-for="day in schedule.weekdays" :key="day.weekday">
                        <SwitchRow
                            :label="day.label"
                            :data-test="`training-${day.weekday}`"
                            :checked="draft.training[day.weekday] !== null"
                            :disabled="busy"
                            @change="toggleTraining(day.weekday, ($event.target as HTMLInputElement).checked)"
                        />
                        <template v-if="draft.training[day.weekday]">
                            <label :class="row">
                                <span>Starts</span>
                                <input v-model="draft.training[day.weekday]!.start" :data-test="`training-start-${day.weekday}`" type="time" :aria-label="`${day.label} starts`" :disabled="busy" :class="time" />
                            </label>
                            <div class="px-4 py-2">
                                <Stepper
                                    v-model="draft.training[day.weekday]!.minutes"
                                    :label="`${day.label} length`"
                                    :text="`Length: ${draft.training[day.weekday]!.minutes} min`"
                                    :min="schedule.session_minutes_min"
                                    :max="schedule.session_minutes_max"
                                    :step="schedule.session_minutes_step"
                                    :disabled="busy"
                                />
                            </div>
                        </template>
                    </template>
                </div>
            </section>

            <section aria-labelledby="schedule-busy">
                <h2 id="schedule-busy" :class="heading">Busy periods</h2>
                <div :class="card">
                    <div v-for="(period, index) in draft.busy" :key="period.key" class="space-y-2 px-4 py-3">
                        <div class="flex items-center gap-2">
                            <input
                                v-model="period.label"
                                :data-test="`busy-label-${index}`"
                                type="text"
                                maxlength="50"
                                aria-label="Label"
                                placeholder="Label"
                                :disabled="busy"
                                class="min-h-tap w-full bg-transparent"
                            />
                            <button
                                type="button"
                                :data-test="`busy-remove-${index}`"
                                aria-label="Remove busy period"
                                :disabled="busy"
                                class="flex min-h-tap min-w-tap items-center justify-center text-tone-warning active:opacity-70"
                                @click="draft.busy.splice(index, 1)"
                            >
                                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.75">
                                    <path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13" />
                                </svg>
                            </button>
                        </div>
                        <label class="flex min-h-tap items-center justify-between gap-3">
                            <span>From</span>
                            <input v-model="period.start" :data-test="`busy-start-${index}`" type="time" :disabled="busy" :class="time" />
                        </label>
                        <label class="flex min-h-tap items-center justify-between gap-3">
                            <span>To</span>
                            <input v-model="period.end" :data-test="`busy-end-${index}`" type="time" :disabled="busy" :class="time" />
                        </label>
                        <div :data-test="`busy-days-${index}`">
                            <WeekdayPicker :days="pickerDays(period)" :disabled="busy" @toggle="toggleBusyDay(period, $event)" />
                        </div>
                    </div>
                    <button
                        type="button"
                        data-test="add-busy"
                        :disabled="busy"
                        class="flex min-h-tap w-full items-center gap-2 px-4 text-accent active:opacity-70 disabled:opacity-60"
                        @click="draft.busy.push(busyFrom(schedule.new_busy_period))"
                    >
                        <span aria-hidden="true">+</span> Add busy period
                    </button>
                </div>
                <p :class="footer">Meals are not placed while you are busy.</p>
            </section>

            <section aria-labelledby="schedule-steps">
                <h2 id="schedule-steps" :class="heading">Step plan</h2>
                <div :class="card">
                    <StepRangeRow
                        v-for="day in schedule.weekdays"
                        :key="day.weekday"
                        v-model:min="draft.steps[day.weekday]!.min"
                        v-model:max="draft.steps[day.weekday]!.max"
                        :weekday="day.weekday"
                        :label="day.label"
                        :disabled="busy"
                    />
                </div>
                <p :class="footer">A daily range, used for your activity level. Leave a day empty for none.</p>
                <p v-if="notWhole" class="px-4 pt-1 text-sm text-tone-warning" data-test="not-whole">Type steps as whole numbers, for example 8000.</p>
                <p v-if="halfRange" class="px-4 pt-1 text-sm text-tone-warning" data-test="half-range">Fill in both numbers for a day, or leave both empty.</p>
            </section>

            <!-- Only once there is a plan: its weeks start on this day. -->
            <section v-if="draft.review_weekday !== null" aria-label="Review day">
                <div :class="card">
                    <label :class="row">
                        <span>Review day</span>
                        <select v-model.number="draft.review_weekday" data-test="review-day" :disabled="busy" class="rounded-lg bg-transparent text-right text-secondary-text disabled:opacity-60">
                            <option v-for="day in schedule.weekdays" :key="day.weekday" :value="day.weekday">{{ day.label }}</option>
                        </select>
                    </label>
                </div>
                <p :class="footer">The day your plan weeks start on.</p>
            </section>
        </template>

        <ReadState v-else :failure="failure" what="Your schedule" @retry="load" />
    </main>
</template>
