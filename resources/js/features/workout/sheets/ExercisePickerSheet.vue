<script setup lang="ts">
import { computed, ref } from 'vue';
import ReadState from '@/app/ReadState.vue';
import { ulid } from '@/app/ulid';
import { useRead } from '@/app/useRead';
import type { ExercisesShowOutput } from '@/contracts/exercises_show';
import type { WorkoutExerciseAddInput } from '@/contracts/workout_exercise_add';
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import BottomSheet from '@/design/BottomSheet.vue';
import type { WorkoutWrite } from '../workoutWrite';
import RemainingWeeksCheckbox from './RemainingWeeksCheckbox.vue';

// Add an exercise to this session (WO-031): the whole library, searchable (EX-008). The
// script holds the search text and the checkbox (VIEW-002). Ported from rp-clone's
// ExercisePickerSheet. `library` is a /dev/screens fixture; the sheet reads it otherwise.
const props = defineProps<{ payload?: WorkoutShowOutput; library?: ExercisesShowOutput }>();

const emit = defineEmits<{ send: [write: WorkoutWrite]; close: [] }>();

const { data, failure, load } = useRead<ExercisesShowOutput>('exercises_show', {}, 'The exercise library could not be loaded.', props.library);

const query = ref('');
const remainingWeeks = ref(false);
const matches = computed(() => (data.value?.exercises ?? []).filter((exercise) => exercise.name.toLowerCase().includes(query.value.trim().toLowerCase())));

function add(exercise: ExercisesShowOutput['exercises'][number]): void {
    if (!props.payload) return;
    const id = ulid();
    const input: WorkoutExerciseAddInput = { workout_id: props.payload.workout_id, workout_exercise_id: id, exercise_id: exercise.id, remaining_weeks: remainingWeeks.value };
    emit('send', { name: 'workout_exercise_add', input: { ...input }, label: `Added ${exercise.name}`, creates: [id] });
    emit('close');
}
</script>

<template>
    <BottomSheet :open="true" title="Add exercise" :save-title="null" @cancel="emit('close')">
        <div class="flex flex-col gap-2 p-4">
            <ReadState v-if="!data" :failure="failure" what="The exercise library" @retry="load().catch(() => undefined)" />
            <template v-else>
                <input
                    v-model="query"
                    type="search"
                    aria-label="Search exercises"
                    placeholder="Search exercises"
                    class="min-h-tap w-full rounded-xl bg-background px-3.5 text-primary-text outline-none placeholder:text-secondary-text focus:ring-2 focus:ring-accent"
                />
                <RemainingWeeksCheckbox v-model="remainingWeeks" />
                <p v-if="!matches.length" class="py-2 text-sm text-secondary-text" data-test="none">No exercises</p>
                <button
                    v-for="exercise in matches"
                    :key="exercise.id"
                    type="button"
                    class="flex min-h-tap w-full flex-col justify-center border-t border-secondary-text/25 py-2 text-left"
                    data-test="exercise"
                    @click="add(exercise)"
                >
                    <span class="text-primary-text" data-test="name">{{ exercise.name }}</span>
                    <span class="text-sm text-secondary-text">{{ exercise.detail }}</span>
                </button>
            </template>
        </div>
    </BottomSheet>
</template>
