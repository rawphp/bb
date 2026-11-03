<script setup lang="ts">
import { ref } from 'vue';
import type { WorkoutExerciseReorderInput } from '@/contracts/workout_exercise_reorder';
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import BottomSheet from '@/design/BottomSheet.vue';
import type { WorkoutWrite } from '../workoutWrite';
import RemainingWeeksCheckbox from './RemainingWeeksCheckbox.vue';

// Reorder with explicit up and down buttons, never drag handles that fire by accident
// (UI-020, WO-031). Each tap is one move; the server says which can move. The script holds
// the checkbox (VIEW-002). Ported from rp-clone's ReorderSheet.
const props = defineProps<{ payload?: WorkoutShowOutput }>();

const emit = defineEmits<{ send: [write: WorkoutWrite]; close: [] }>();

const remainingWeeks = ref(false);

type Exercise = WorkoutShowOutput['exercises'][number];

/** Positions count from 1: one place up is the index it has now. */
function move(exercise: Exercise, position: number, verb: string): void {
    const input: WorkoutExerciseReorderInput = { workout_exercise_id: exercise.id, position, remaining_weeks: remainingWeeks.value };
    emit('send', { name: 'workout_exercise_reorder', input: { ...input }, label: `Moved ${exercise.name} ${verb}` });
}

const moveButton = 'flex min-h-tap min-w-tap items-center justify-center text-accent disabled:text-secondary-text disabled:opacity-40';
</script>

<template>
    <BottomSheet :open="true" title="Reorder exercises" cancel-title="Done" :save-title="null" :heights="['half', 'full']" @cancel="emit('close')">
        <div class="flex flex-col p-4">
            <RemainingWeeksCheckbox v-model="remainingWeeks" />
            <p v-if="!payload?.exercises.length" class="py-3 text-sm text-secondary-text" data-test="none">This workout has no exercises.</p>
            <div v-for="(exercise, index) in payload?.exercises" :key="exercise.id" :data-exercise="exercise.id" class="flex items-center gap-2 border-t border-secondary-text/25 first:border-t-0">
                <span class="min-w-0 flex-1 py-2 text-primary-text" data-test="name">{{ exercise.name }}</span>
                <button type="button" :class="moveButton" :aria-label="`Move ${exercise.name} up`" :disabled="!exercise.can_move_up" @click="move(exercise, index, 'up')">
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7" /></svg>
                </button>
                <button type="button" :class="moveButton" :aria-label="`Move ${exercise.name} down`" :disabled="!exercise.can_move_down" @click="move(exercise, index + 2, 'down')">
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12l7 7 7-7" /></svg>
                </button>
            </div>
        </div>
    </BottomSheet>
</template>
