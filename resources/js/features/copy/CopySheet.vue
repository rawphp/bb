<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import ReadState from '@/app/ReadState.vue';
import { screenRead, type ReadFailure } from '@/app/screenRead';
import { useWrite } from '@/app/useWrite';
import type { CopyShowInput, CopyShowOutput } from '@/contracts/copy_show';
import type { DayCopyInput } from '@/contracts/day_copy';
import type { MealCopyInput } from '@/contracts/meal_copy';
import BottomSheet from '@/design/BottomSheet.vue';
import CopyMealRowView from './CopyMealRowView.vue';
import CopyOptionsSheet from './CopyOptionsSheet.vue';
import CopySourceCard from './CopySourceCard.vue';
import CopyWeekNavigator from './CopyWeekNavigator.vue';
import CopyWorkoutBlockView from './CopyWorkoutBlockView.vue';

// Copy foods (MEAL-060) and Copy day (SCH-034): the source, a week strip, the destinations,
// and a gear for the three advanced options. Save writes every destination at once; Cancel
// discards. Ported from rp-clone's CopySheet. The server says which days and meals can take
// the copy; the sheet keeps only what the user has picked. `pending` dims the server's
// calories and targets while a write waits to sync (OFF-008).
const props = withDefaults(defineProps<{ payload?: CopyShowOutput; mealId?: string; sourceDate?: string; pending?: boolean }>(), { pending: false });
const emit = defineEmits<{ close: [] }>();

// Showing another day or week reads copy_show again for it. Each read gets its own input,
// so one still on its way keeps the day it asked for (screenRead ignores its late answer).
const data = ref<CopyShowOutput | undefined>(props.payload);
const failure = ref<ReadFailure | null>(null);
const shownDate = ref<string | null>(null);
const read = screenRead<CopyShowOutput>(
    'copy_show',
    'The copy could not be loaded.',
    (shown) => {
        failure.value = null;
        data.value = shown;
    },
    (why) => {
        data.value = undefined;
        failure.value = why;
    },
);
const load = (options?: { fresh?: boolean }): Promise<void> =>
    read({ meal_id: props.mealId ?? null, source_date: props.sourceDate ?? null, shown_date: shownDate.value } satisfies CopyShowInput, options);
const { send, busy, error } = useWrite(load);

// Offline with nothing to show, it reads again when the device reconnects (OFF-004), as useRead does.
const reconnected = (): void => {
    if (failure.value?.offline) void load();
};
onMounted(() => {
    window.addEventListener('online', reconnected);
    if (!data.value) void load();
});
onUnmounted(() => window.removeEventListener('online', reconnected));

const open = ref(true);
const showsAdvanced = ref(false);
const meals = ref<string[]>([]);
const days = ref<string[]>([]);
const options = ref<CopyShowOutput['options'] | null>(null);

watch(
    data,
    (shown) => {
        if (shown && !options.value) options.value = { ...shown.options };
    },
    { immediate: true },
);

const dayMode = computed(() => data.value?.mode === 'day');
const marked = computed(() => (dayMode.value ? days.value : data.value ? [data.value.shown_date] : []));
type Meal = CopyShowOutput['meals'][number];
type Workout = CopyShowOutput['workouts'][number];

// The shown day's meals and workout in the order the day runs.
const timeline = computed(() =>
    (data.value?.timeline ?? []).flatMap((item): { key: string; meal?: Meal; workout?: Workout }[] => {
        const meal = item.kind === 'meal' ? data.value?.meals.find((m) => m.id === item.id) : undefined;
        const workout = item.kind === 'workout' ? data.value?.workouts.find((w) => w.id === item.id) : undefined;
        return meal ? [{ key: `meal-${meal.id}`, meal }] : workout ? [{ key: `workout-${workout.id}`, workout }] : [];
    }),
);
const ready = computed(() => (dayMode.value ? days.value.length > 0 && !!props.sourceDate : meals.value.length > 0 && !!props.mealId));

function flip(list: string[], id: string): string[] {
    return list.includes(id) ? list.filter((other) => other !== id) : [...list, id];
}

function show(date: string | null | undefined): void {
    if (!date) return;
    shownDate.value = date;
    void load();
}

function pickDay(date: string): void {
    const day = data.value?.strip.find((d) => d.date === date);
    if (!day?.enabled) return;
    if (dayMode.value) days.value = flip(days.value, date);
    else if (date !== data.value?.shown_date) show(date);
}

function pickMeal(meal: Meal): void {
    if (meal.enabled) meals.value = flip(meals.value, meal.id);
}

function close(): void {
    if (!open.value) return;
    open.value = false;
    emit('close');
}

async function save(): Promise<void> {
    if (!ready.value || !options.value) return;
    const outcome = dayMode.value
        ? await send('day_copy', { source_date: props.sourceDate!, target_dates: days.value, ...options.value } satisfies DayCopyInput, 'The day could not be copied.')
        : await send('meal_copy', { meal_id: props.mealId!, target_meal_ids: meals.value, ...options.value } satisfies MealCopyInput, 'The foods could not be copied.');
    if (outcome === 'done') close();
}
</script>

<template>
    <BottomSheet
        :open="open"
        :title="data?.title ?? (sourceDate ? 'Copy day' : 'Copy foods')"
        :save-disabled="!ready || busy"
        :cancel-disabled="busy"
        :dismissible="!busy"
        :error="error"
        @cancel="close"
        @save="save"
    >
        <div v-if="data" class="space-y-3.5 p-4" :aria-busy="busy || undefined">
            <CopySourceCard :source="data.source" />

            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-primary-text">{{ dayMode ? 'Copy to days' : 'Copy to meals' }}</h3>
                <button type="button" class="flex min-h-tap min-w-tap items-center justify-center text-accent active:opacity-70" aria-label="Advanced options" @click="showsAdvanced = true">
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.75">
                        <circle cx="12" cy="12" r="3" />
                        <path
                            d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"
                        />
                    </svg>
                </button>
            </div>

            <CopyWeekNavigator
                :strip="data.strip"
                :selected="marked"
                :has-previous="!!data.previous_week"
                :has-next="!!data.next_week"
                :pending="pending"
                :disabled="busy"
                @select="pickDay"
                @week="(step) => show(step === -1 ? data?.previous_week : data?.next_week)"
            />

            <p v-if="dayMode" class="text-sm text-secondary-text" data-test="day-hint">
                {{ days.length === 0 ? "Tap the days to copy this day's foods to." : `${days.length} ${days.length === 1 ? 'day' : 'days'} chosen` }}
            </p>
            <template v-else>
                <div v-if="timeline.length" class="space-y-2" data-test="timeline">
                    <template v-for="item in timeline" :key="item.key">
                        <CopyMealRowView v-if="item.meal" :row="item.meal" :selected="meals.includes(item.meal.id)" :pending="pending" @click="pickMeal(item.meal)" />
                        <CopyWorkoutBlockView v-else-if="item.workout" :block="item.workout" />
                    </template>
                </div>
                <p v-else class="text-sm text-secondary-text" data-test="no-meals">No meals planned this day.</p>
                <p v-if="meals.length" class="text-sm font-semibold text-secondary-text" data-test="chosen">
                    {{ meals.length }} {{ meals.length === 1 ? 'meal' : 'meals' }} chosen
                </p>
            </template>
        </div>
        <div v-else class="p-4"><ReadState :failure="failure" what="The copy" @retry="load" /></div>
    </BottomSheet>

    <CopyOptionsSheet
        v-if="options"
        :open="open && showsAdvanced"
        :options="options"
        @cancel="showsAdvanced = false"
        @done="(changed) => ((options = changed), (showsAdvanced = false))"
    />
</template>
