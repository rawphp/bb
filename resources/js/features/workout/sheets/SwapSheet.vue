<script setup lang="ts">
import { computed, ref } from 'vue';
import ReadState from '@/app/ReadState.vue';
import { ulid } from '@/app/ulid';
import { useRead } from '@/app/useRead';
import type { ExerciseSwapInput } from '@/contracts/exercise_swap';
import type { ExercisesShowOutput } from '@/contracts/exercises_show';
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import BottomSheet from '@/design/BottomSheet.vue';
import ChoiceList from '@/design/ChoiceList.vue';
import { toneClasses } from '@/design/tones';
import type { WorkoutWrite } from '../workoutWrite';

type Scope = ExerciseSwapInput['scope'];

// Swap an exercise for another of the same muscle (EX-008), for this session or for the
// rest of the mesocycle. The script holds the scope (VIEW-002). Ported from rp-clone's
// SwapSheet. `library` is a /dev/screens fixture; the sheet reads it otherwise.
const props = defineProps<{ payload?: WorkoutShowOutput; /** The workout exercise's id. */ exerciseId: string; library?: ExercisesShowOutput }>();

const emit = defineEmits<{ send: [write: WorkoutWrite]; close: [] }>();

const { data, failure, load } = useRead<ExercisesShowOutput>('exercises_show', {}, 'The exercise library could not be loaded.', props.library);

const scope = ref<Scope>('this_session');
const scopes: { id: Scope; title: string }[] = [
    { id: 'this_session', title: 'This session only' },
    { id: 'rest_of_mesocycle', title: 'Rest of the mesocycle' },
];

const current = computed(() => props.payload?.exercises.find((found) => found.id === props.exerciseId));
const candidates = computed(() => (data.value?.exercises ?? []).filter((exercise) => exercise.muscle_group === current.value?.muscle_group && exercise.id !== current.value?.exercise_id));

function swap(exercise: ExercisesShowOutput['exercises'][number]): void {
    if (!current.value) return;
    const id = ulid();
    const input: ExerciseSwapInput = { workout_exercise_id: current.value.id, new_workout_exercise_id: id, exercise_id: exercise.id, scope: scope.value };
    emit('send', { name: 'exercise_swap', input: { ...input }, label: `Swapped ${current.value.name} for ${exercise.name}`, creates: [id] });
    emit('close');
}
</script>

<template>
    <BottomSheet :open="true" title="Swap exercise" :save-title="null" @cancel="emit('close')">
        <div class="flex flex-col gap-3 p-4">
            <p v-if="payload" class="rounded-xl p-3 text-sm" :class="toneClasses.info" data-test="advice">{{ payload.swap_advice }}</p>
            <ChoiceList v-model="scope" label="Swap scope" :choices="scopes" />
            <h3 class="pt-2 text-sm font-semibold text-secondary-text">Same muscle</h3>
            <ReadState v-if="!data" :failure="failure" what="The exercise library" @retry="load().catch(() => undefined)" />
            <template v-else>
                <p v-if="!candidates.length" class="py-2 text-sm text-secondary-text" data-test="none">No exercises</p>
                <button
                    v-for="exercise in candidates"
                    :key="exercise.id"
                    type="button"
                    class="flex min-h-tap w-full flex-col justify-center border-t border-secondary-text/25 py-2 text-left"
                    data-test="exercise"
                    @click="swap(exercise)"
                >
                    <span class="text-primary-text" data-test="name">{{ exercise.name }}</span>
                    <span class="text-sm text-secondary-text">{{ exercise.detail }}</span>
                </button>
            </template>
        </div>
    </BottomSheet>
</template>
