<script setup lang="ts">
import { computed, ref } from 'vue';
import { ulid } from '@/app/ulid';
import type { BusyPeriodSetInput } from '@/contracts/busy_period_set';
import BottomSheet from '@/design/BottomSheet.vue';
import FormSection from './FormSection.vue';
import ScheduleSheetMessage from './ScheduleSheetMessage.vue';
import SheetBody from './SheetBody.vue';
import TimeField from './TimeField.vue';
import { useDaySheet, type DaySheetProps } from './useDaySheet';
import { isoWeekdayOf } from './weekday';
import WeekdayChips from './WeekdayChips.vue';

// SCH-033: the schedule menu's Add busy period. A label, a start and end, and the
// weekdays it repeats on, starting with this day's. It joins the schedule, so it applies
// from tomorrow (SET-004). Ported from rp-clone's BusyPeriodSheet.
const props = defineProps<DaySheetProps>();

const emit = defineEmits<{ close: [saved: boolean] }>();

const { data, failure, load, send, busy, error, seed } = useDaySheet(props);

const days = computed(() => data.value?.schedule?.training_weekdays ?? []);
// One id for the period this sheet adds, so a retry after no answer is the same create (CAP-019).
const id = ulid();
const label = ref('');
const start = ref('09:00');
const end = ref('10:00');
const weekdays = ref<number[]>([]);
seed((day) => {
    const iso = isoWeekdayOf(day.date, day.schedule?.training_weekdays ?? []);
    if (iso !== undefined) weekdays.value = [iso];
});

// Kept in the order the server lists the weekdays, Monday first.
function toggle(iso: number): void {
    const on = weekdays.value.includes(iso) ? weekdays.value.filter((day) => day !== iso) : [...weekdays.value, iso];
    weekdays.value = days.value.map((day) => day.iso).filter((day) => on.includes(day));
}

const canSave = computed(
    () => !!data.value?.schedule && label.value.trim() !== '' && weekdays.value.length > 0 && start.value !== '' && end.value !== '' && start.value !== end.value && !busy.value,
);

async function save(): Promise<void> {
    if (!canSave.value) return;
    const input = { id, label: label.value.trim(), start: start.value, end: end.value, weekdays: weekdays.value } satisfies BusyPeriodSetInput;
    if ((await send('busy_period_set', input, 'The busy period could not be saved.')) === 'done') emit('close', true);
}
</script>

<template>
    <BottomSheet
        :open="true"
        title="Add busy period"
        :save-disabled="!canSave"
        :cancel-disabled="busy"
        :dismissible="!busy"
        :pending="pending"
        @cancel="emit('close', false)"
        @save="save"
    >
        <SheetBody :loaded="!!data" :failure="failure" what="This day" @retry="load">
            <template v-if="data?.schedule">
                <FormSection>
                    <input
                        v-model="label"
                        type="text"
                        aria-label="Label"
                        placeholder="Label"
                        :disabled="busy"
                        class="min-h-tap w-full bg-transparent py-2 text-primary-text placeholder:text-secondary-text/70 focus:outline-none"
                    />
                    <TimeField v-model="start" label="From" :disabled="busy" />
                    <TimeField v-model="end" label="To" :disabled="busy" />
                </FormSection>
                <FormSection header="Repeats on" footer="Applies from tomorrow. To change today, use Edit schedule for this day.">
                    <WeekdayChips :days="days" :selected="weekdays" :disabled="busy" @toggle="toggle" />
                </FormSection>
                <ScheduleSheetMessage :text="error" />
            </template>
            <p v-else-if="data" class="px-4 text-secondary-text">There is no schedule to change yet.</p>
        </SheetBody>
    </BottomSheet>
</template>
