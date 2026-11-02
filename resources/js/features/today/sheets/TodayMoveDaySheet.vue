<script setup lang="ts">
import { computed, ref } from 'vue';
import type { WorkoutMoveInput } from '@/contracts/workout_move';
import BottomSheet from '@/design/BottomSheet.vue';
import ChoiceList, { type Choice } from '@/design/ChoiceList.vue';
import SheetBody from './SheetBody.vue';
import { useDaySheet, type DaySheetProps } from './useDaySheet';

// SCH-051: the workout menu's Move to another day lists the days of the plan week. A day
// that holds a session is not offered and says why (SCH-038). A tap moves it at once.
// Ported from rp-clone's TodayMoveDaySheet.
const props = defineProps<DaySheetProps & { workoutId?: string }>();

const emit = defineEmits<{ close: [saved: boolean] }>();

const { data, failure, load, send, busy, error } = useDaySheet(props);

const workout = computed(() => data.value?.workouts.find((found) => props.workoutId === undefined || found.id === props.workoutId));
const days = computed<Choice<string>[]>(
    () => workout.value?.move_days.map((day) => ({ id: day.date, title: day.label, detail: day.note ?? undefined, isEnabled: day.enabled })) ?? [],
);
const picked = ref<string | null>(null);

async function move(date: string): Promise<void> {
    const session = workout.value;
    if (!session) return;
    picked.value = date;
    const input = { mesocycle_id: session.mesocycle_id, week: session.week, day: session.day, date } satisfies WorkoutMoveInput;
    if ((await send('workout_move', input, 'The workout could not be moved.')) === 'done') emit('close', true);
}
</script>

<template>
    <BottomSheet
        :open="true"
        title="Move to another day"
        :save-title="null"
        :cancel-disabled="busy"
        :dismissible="!busy"
        :pending="pending"
        :heights="['half', 'full']"
        @cancel="emit('close', false)"
        @save="() => undefined"
    >
        <SheetBody :loaded="!!data" :failure="failure" what="This day" @retry="load">
            <template v-if="workout">
                <ChoiceList v-if="days.length" label="Days" :choices="days" :model-value="picked" :disabled="busy" :error="error" @update:model-value="move" />
                <p v-else class="px-4 text-secondary-text">No other day this week is free. A workout stays in its plan week.</p>
            </template>
            <p v-else class="px-4 text-secondary-text">This day has no workout.</p>
        </SheetBody>
    </BottomSheet>
</template>
