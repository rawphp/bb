<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useWrite } from '@/app/useWrite';
import type { WeighInScheduleSetInput } from '@/contracts/weigh_in_schedule_set';
import type { WeighInsShowOutput } from '@/contracts/weigh_ins_show';
import BottomSheet from '@/design/BottomSheet.vue';
import TimeOfDayPicker from '@/features/onboarding/parts/TimeOfDayPicker.vue';
import WeekdayPicker from '@/features/onboarding/parts/WeekdayPicker.vue';
import { toneClasses } from '@/design/tones';

// Add or edit one schedule entry (WT-001, WT-007): Mon to Sun toggles, Select all days, a
// time, Delete schedule. Ported from rp-clone's WeighInScheduleEntrySheet. weigh_in_schedule_set
// takes the whole schedule, so Save sends the entries the server sent with this one put
// in, changed or taken out. The server says whether an entry can go (can_delete) and
// refuses a schedule with too few days.
type Schedule = WeighInsShowOutput['schedule'];
type Entry = WeighInScheduleSetInput['entries'][number];

const props = withDefaults(
    defineProps<{
        open: boolean;
        schedule: Schedule;
        reload: (options?: { fresh?: boolean }) => Promise<void>;
        /** The entry to edit, by its place in the schedule; null adds one. */
        index?: number | null;
    }>(),
    { index: null },
);

const emit = defineEmits<{ cancel: []; done: [] }>();

// A new entry's reminder time until one is picked.
const NEW_TIME = '07:00';

const days = ref<number[]>([]);
const time = ref(NEW_TIME);

const { send, busy, error } = useWrite((options) => props.reload(options));

const entry = computed(() => (props.index === null ? null : (props.schedule.entries[props.index] ?? null)));

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        days.value = entry.value ? [...entry.value.weekdays] : [];
        time.value = entry.value?.time ?? NEW_TIME;
        error.value = null;
    },
    { immediate: true },
);

const pickerDays = computed(() =>
    props.schedule.weekdays.map((day) => ({ weekday: day.number, name: day.short, full_name: day.full, is_on: days.value.includes(day.number) })),
);
const allOn = computed(() => pickerDays.value.every((day) => day.is_on));

function toggle(weekday: number): void {
    days.value = days.value.includes(weekday) ? days.value.filter((day) => day !== weekday) : [...days.value, weekday];
}

function selectAll(): void {
    days.value = props.schedule.weekdays.map((day) => day.number);
}

const two = (n: number) => String(n).padStart(2, '0');
const minutes = computed(() => {
    const [hours = 0, mins = 0] = time.value.split(':').map(Number);
    return hours * 60 + mins;
});

function setMinutes(value: number): void {
    time.value = `${two(Math.floor(value / 60))}:${two(value % 60)}`;
}

/** The schedule as the server sent it, each entry as weigh_in_schedule_set takes it. */
function sent(): Entry[] {
    return props.schedule.entries.map((item) => ({ weekdays: [...item.weekdays] as Entry['weekdays'], time: item.time }));
}

/** This entry's days in the week's order, as the server lists them. */
const draft = computed<Entry | null>(() => {
    const picked = props.schedule.weekdays.map((day) => day.number).filter((number) => days.value.includes(number));
    return picked.length ? { weekdays: picked as Entry['weekdays'], time: time.value } : null;
});

async function write(entries: Entry[], fallback: string): Promise<void> {
    if (!entries.length) return;
    const input = { entries: entries as WeighInScheduleSetInput['entries'] } satisfies WeighInScheduleSetInput;
    if ((await send('weigh_in_schedule_set', input, fallback)) === 'done') emit('done');
}

function save(): void {
    if (!draft.value) return;
    const entries = sent();
    if (props.index === null) entries.push(draft.value);
    else entries.splice(props.index, 1, draft.value);
    void write(entries, 'The schedule could not be saved. Try again.');
}

function remove(): void {
    if (props.index === null) return;
    const entries = sent();
    entries.splice(props.index, 1);
    void write(entries, 'The schedule entry could not be deleted. Try again.');
}
</script>

<template>
    <BottomSheet
        :open="open"
        :title="entry ? 'Edit schedule' : 'Add to schedule'"
        :heights="['half', 'full']"
        :save-disabled="draft === null || busy"
        :cancel-disabled="busy"
        :dismissible="!busy"
        :error="error"
        @cancel="emit('cancel')"
        @save="save"
    >
        <div class="flex flex-col gap-4 p-4">
            <div class="flex items-center justify-between gap-2">
                <h3 class="font-semibold text-primary-text">Days</h3>
                <button
                    type="button"
                    data-test="select-all"
                    :disabled="allOn || busy"
                    class="min-h-tap text-accent active:opacity-70 disabled:text-secondary-text"
                    @click="selectAll"
                >
                    Select all days
                </button>
            </div>
            <WeekdayPicker :days="pickerDays" :disabled="busy" @toggle="toggle" />
            <TimeOfDayPicker label="Time" :model-value="minutes" :disabled="busy" @update:model-value="setMinutes" />
            <p class="text-sm text-secondary-text">{{ schedule.note }}</p>
            <template v-if="entry">
                <button
                    type="button"
                    data-test="delete-entry"
                    :disabled="!entry.can_delete || busy"
                    class="flex min-h-tap items-center justify-center gap-2 rounded-xl bg-accent/15 text-accent active:opacity-70 disabled:bg-background disabled:text-secondary-text"
                    @click="remove"
                >
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6" />
                    </svg>
                    Delete schedule
                </button>
                <p v-if="!entry.can_delete" class="rounded-xl p-3 text-sm" :class="toneClasses.info">{{ schedule.delete_blocked_message }}</p>
            </template>
        </div>
    </BottomSheet>
</template>
