<script setup lang="ts">
import { computed, ref } from 'vue';
import type { WorkoutTimeSetInput } from '@/contracts/workout_time_set';
import BottomSheet from '@/design/BottomSheet.vue';
import SheetBody from './SheetBody.vue';
import TimeField from './TimeField.vue';
import { useDaySheet, type DaySheetProps } from './useDaySheet';

// SCH-050: the workout menu's Change time picks the session's new start. The server
// re-places and re-targets the day's unchecked meals. Ported from rp-clone's TodayWorkoutTimeSheet.
const props = defineProps<DaySheetProps & { workoutId?: string }>();

const emit = defineEmits<{ close: [saved: boolean] }>();

const { data, failure, load, send, busy, error, seed } = useDaySheet(props);

const workout = computed(() => data.value?.workouts.find((found) => props.workoutId === undefined || found.id === props.workoutId));
const time = ref('');
seed(() => (time.value = workout.value?.time ?? ''));

async function save(): Promise<void> {
    const session = workout.value;
    if (!session || !time.value) return;
    const input = { mesocycle_id: session.mesocycle_id, week: session.week, day: session.day, time: time.value } satisfies WorkoutTimeSetInput;
    if ((await send('workout_time_set', input, 'The time could not be changed.')) === 'done') emit('close', true);
}
</script>

<template>
    <BottomSheet
        :open="true"
        title="Change time"
        :save-disabled="!workout || !time || busy"
        :cancel-disabled="busy"
        :dismissible="!busy"
        :error="error"
        :pending="pending"
        :heights="['half']"
        @cancel="emit('close', false)"
        @save="save"
    >
        <SheetBody :loaded="!!data" :failure="failure" what="This day" @retry="load">
            <div v-if="workout" class="rounded-2xl bg-surface px-4">
                <TimeField v-model="time" label="Start" :disabled="busy" />
            </div>
            <p v-else class="px-4 text-secondary-text">This day has no workout.</p>
        </SheetBody>
    </BottomSheet>
</template>
