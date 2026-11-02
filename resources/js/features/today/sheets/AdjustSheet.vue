<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue';
import ReadState from '@/app/ReadState.vue';
import type { DayAdjustInput } from '@/contracts/day_adjust';
import type { DayAdjustShowInput, DayAdjustShowOutput } from '@/contracts/day_adjust_show';
import BottomSheet from '@/design/BottomSheet.vue';
import PendingMark from '@/design/PendingMark.vue';
import SegmentedPicker from './SegmentedPicker.vue';
import WarningLine from './WarningLine.vue';
import { useSheet, useSheetRead, type SheetListeners } from './useSheet';

// DAY-020: moves calories between the day's meals, or between the days of the plan week,
// and shows the day's projected calories and protein before saving. The server works the
// move out (day_adjust_show) each time the user changes it; the sheet keeps only what is
// picked and typed.
const props = withDefaults(
    defineProps<
        {
            /** The day, from the screen or the route; a /dev/screens fixture carries its own. */
            date?: string;
            payload?: DayAdjustShowOutput;
            /** A change is waiting to sync, so the projection is not final (OFF-008). */
            pending?: boolean;
        } & SheetListeners
    >(),
    { pending: false },
);

type Mode = DayAdjustShowOutput['mode'];
type Direction = NonNullable<DayAdjustInput['direction']>;

const date = props.date ?? props.payload?.date ?? '';
// null until the user picks: the sheet opens in the mode the server shows.
const picked = ref<Mode | null>(null);
const direction = ref<Direction>('give');
const fromMealId = ref<string | null>(null);
const toMealId = ref<string | null>(null);
const otherDate = ref<string | null>(null);
const kcalText = ref('');
const edited = ref(false);

// The typed amount as the whole number the capabilities take; anything else is none, and the server asks for one.
const kcal = computed(() => {
    const text = kcalText.value.trim();
    return /^\d+$/.test(text) ? Number(text) : null;
});

const { data, failure, reading, notice, load } = useSheetRead<DayAdjustShowOutput>(
    'day_adjust_show',
    () => ({ ...choice() }),
    'The Adjust sheet could not be loaded.',
    props.payload,
);
const { busy, error, locked, close, save } = useSheet(props);
// Save follows the server's answer for the move as it is now, never an older one.
const canSave = computed(
    () => data.value?.can_save === true && kcal.value !== null && !typing.value && !reading.value && failure.value === null && !busy.value,
);
const mode = computed<Mode>(() => picked.value ?? data.value?.mode ?? 'meals');
// The day's totals, then the other day's when the move is between days.
const days = computed(() => (data.value ? [data.value.day, ...(data.value.other_day ? [data.value.other_day] : [])] : []));

/** What the user has filled in, for the mode shown; just the day until they change something. */
function choice(): DayAdjustShowInput {
    if (!edited.value) return { date };
    return mode.value === 'days'
        ? { date, mode: 'days', direction: direction.value, other_date: otherDate.value, kcal: kcal.value }
        : { date, mode: 'meals', from_meal_id: fromMealId.value, to_meal_id: toMealId.value, kcal: kcal.value };
}

// A pick asks the server at once; the amount once typing stops, not on every key.
const DEBOUNCE_MS = 300;
// An amount has been typed that the server has not been asked about yet.
const typing = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

function ask(): void {
    clearTimeout(timer);
    typing.value = false;
    edited.value = true;
    void load({ fresh: true });
}

watch([picked, direction, fromMealId, toMealId, otherDate], ask);
watch(kcalText, () => {
    clearTimeout(timer);
    typing.value = true;
    timer = setTimeout(ask, DEBOUNCE_MS);
});
onUnmounted(() => clearTimeout(timer));

function submit(): void {
    if (!canSave.value || kcal.value === null) return;
    const input: DayAdjustInput = { ...choice(), mode: mode.value, kcal: kcal.value };
    void save('day_adjust', { ...input }, 'The calories could not be moved. Try again.');
}

const modes: { value: Mode; label: string }[] = [
    { value: 'meals', label: 'Between meals' },
    { value: 'days', label: 'Between days' },
];
const directions: { value: Direction; label: string }[] = [
    { value: 'give', label: 'Give to' },
    { value: 'take', label: 'Take from' },
];
const pickerRow = 'flex min-h-tap items-center justify-between gap-2';
const picker = 'max-w-[60%] truncate rounded-lg bg-transparent py-2 pl-2 text-right text-accent';
</script>

<template>
    <BottomSheet
        :open="true"
        title="Adjust day"
        :save-disabled="!canSave"
        :cancel-disabled="busy"
        :dismissible="!busy"
        :error="error ?? notice"
        @cancel="close"
        @save="submit"
    >
        <!-- Locked while a save that got no answer may have worked (CAP-014): the retry sends the same move. -->
        <fieldset v-if="data" :disabled="locked" class="flex min-w-0 flex-col gap-4.5 p-4">
            <SegmentedPicker label="Move calories" :options="modes" :model-value="mode" @update:model-value="picked = $event" />

            <div v-if="mode === 'meals'" class="flex flex-col gap-2">
                <p v-if="data.meal_options.length < 2" class="text-sm text-secondary-text">
                    Calories move between meals that are not checked in. This day has fewer than two.
                </p>
                <template v-else>
                    <label :class="pickerRow">
                        <span>From</span>
                        <select v-model="fromMealId" name="from" :class="picker">
                            <option :value="null" disabled>Choose</option>
                            <option v-for="option in data.meal_options" :key="option.id" :value="option.id">{{ option.name }}</option>
                        </select>
                    </label>
                    <label :class="pickerRow">
                        <span>To</span>
                        <select v-model="toMealId" name="to" :class="picker">
                            <option :value="null" disabled>Choose</option>
                            <option v-for="option in data.meal_options" :key="option.id" :value="option.id">{{ option.name }}</option>
                        </select>
                    </label>
                </template>
            </div>

            <div v-else class="flex flex-col gap-2">
                <SegmentedPicker v-model="direction" label="Direction" :options="directions" />
                <label :class="pickerRow">
                    <span>Day</span>
                    <select v-model="otherDate" name="day" :class="picker">
                        <option :value="null" disabled>Choose</option>
                        <option v-for="option in data.day_options" :key="option.date" :value="option.date" :disabled="!option.enabled">
                            {{ option.enabled ? option.label : `${option.label} (finished)` }}
                        </option>
                    </select>
                </label>
            </div>

            <label class="flex min-h-tap items-center gap-2 rounded-xl bg-background px-3">
                <span class="text-macro-calories">Calories</span>
                <input
                    v-model="kcalText"
                    name="kcal"
                    inputmode="numeric"
                    placeholder="0"
                    aria-label="Calories to move in kilocalories"
                    class="min-w-0 flex-1 bg-transparent py-2 text-right tabular-nums outline-none"
                />
                <span class="text-secondary-text">kcal</span>
            </label>

            <section class="flex flex-col gap-2 rounded-xl bg-background p-3.5" aria-labelledby="adjust-projected">
                <h3 id="adjust-projected" class="font-semibold">Projected</h3>
                <p
                    v-for="day in days"
                    :key="day.label"
                    class="flex items-baseline gap-2 text-sm font-medium tabular-nums"
                    data-test="projected"
                >
                    <span class="flex-1">{{ day.label }}</span>
                    <component :is="pending ? PendingMark : 'span'" :derived="pending || undefined">
                        <span class="text-macro-calories">{{ day.kcal_text }}</span>
                        <span class="ml-2 text-macro-protein">{{ day.protein_text }}</span>
                    </component>
                </p>
                <template v-if="mode === 'meals'">
                    <p
                        v-for="meal in data.projected_meals"
                        :key="meal.id"
                        class="flex items-baseline gap-2 text-xs font-medium tabular-nums"
                        data-test="projected-meal"
                    >
                        <span class="flex-1">{{ meal.name }}</span>
                        <component :is="pending ? PendingMark : 'span'" :derived="pending || undefined">
                            <span class="text-macro-calories">{{ meal.kcal_text }}</span>
                            <span class="ml-2 text-macro-protein">{{ meal.protein_text }}</span>
                        </component>
                    </p>
                </template>
            </section>

            <WarningLine v-if="data.refusal" :text="data.refusal" />
            <WarningLine v-for="warning in data.warnings" :key="warning.text" :text="warning.text" />
            <p class="text-xs text-secondary-text">{{ data.note }}</p>
        </fieldset>
        <div v-else class="p-4">
            <ReadState :failure="failure" what="The Adjust sheet" @retry="load" />
        </div>
    </BottomSheet>
</template>
