<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { ulid } from '@/app/ulid';
import { useWrite } from '@/app/useWrite';
import type { CustomExerciseSaveInput } from '@/contracts/custom_exercise_save';
import type { ExercisesShowOutput } from '@/contracts/exercises_show';
import BottomSheet from '@/design/BottomSheet.vue';

// Create a custom exercise, or edit one (EX-004, EX-005), ported from rp-clone's
// CustomExerciseSheet.swift. The choices and their labels are exercises_show's, and the
// server checks every value. `reload` is the read of the screen underneath, which
// useWrite runs after a save, or fresh after one that got no final answer (CAP-014).
type Choices = Pick<ExercisesShowOutput, 'muscle_groups' | 'equipment' | 'load_types'>;
type Exercise = Pick<CustomExerciseSaveInput, 'name' | 'muscle_group' | 'equipment' | 'load_type'> & { id: string };

const props = withDefaults(
    defineProps<{
        open: boolean;
        reload: (options?: { fresh?: boolean }) => Promise<void>;
        choices: Choices;
        /** The custom exercise to change; null creates a new one. */
        exercise?: Exercise | null;
    }>(),
    { exercise: null },
);

const emit = defineEmits<{ cancel: []; saved: [id: string] }>();

const id = ref('');
const name = ref('');
const muscle = ref('');
const equipment = ref('');
const loadType = ref('');

const { send, busy, error } = useWrite((options) => props.reload(options));

// Each opening starts from the exercise, or blank with a new id for a create (CAP-019).
function reset(): void {
    const exercise = props.exercise;
    id.value = exercise?.id ?? ulid();
    name.value = exercise?.name ?? '';
    muscle.value = exercise?.muscle_group ?? '';
    equipment.value = exercise?.equipment ?? '';
    loadType.value = exercise?.load_type ?? '';
    error.value = null;
}

watch(
    () => props.open,
    (open) => open && reset(),
    { immediate: true },
);

const input = computed<CustomExerciseSaveInput | null>(() => {
    if (name.value.trim() === '' || !muscle.value || !equipment.value || !loadType.value) return null;

    return {
        exercise_id: id.value,
        name: name.value.trim(),
        muscle_group: muscle.value as CustomExerciseSaveInput['muscle_group'],
        equipment: equipment.value as CustomExerciseSaveInput['equipment'],
        load_type: loadType.value as CustomExerciseSaveInput['load_type'],
    };
});

const pickers = computed(() => [
    { key: 'muscle_group', label: 'Muscle group', model: muscle, options: props.choices.muscle_groups },
    { key: 'equipment', label: 'Equipment', model: equipment, options: props.choices.equipment },
    { key: 'load_type', label: 'Load type', model: loadType, options: props.choices.load_types },
]);

async function save(): Promise<void> {
    if (!input.value) return;
    const saving = input.value;
    if ((await send('custom_exercise_save', { ...saving }, 'The exercise could not be saved. Try again.')) === 'done') emit('saved', saving.exercise_id);
}
</script>

<template>
    <BottomSheet
        :open="open"
        :title="exercise ? 'Edit exercise' : 'New exercise'"
        :save-disabled="input === null || busy"
        :cancel-disabled="busy"
        :dismissible="!busy"
        :error="error"
        @cancel="emit('cancel')"
        @save="save"
    >
        <form class="flex flex-col gap-4 p-4" @submit.prevent="save">
            <input
                v-model="name"
                name="name"
                type="text"
                aria-label="Name"
                placeholder="Name"
                autocapitalize="words"
                autocomplete="off"
                maxlength="255"
                :disabled="busy"
                class="min-h-tap w-full rounded-xl bg-background px-3.5 text-base text-primary-text placeholder:text-secondary-text"
            />
            <label v-for="picker in pickers" :key="picker.key" class="flex min-h-tap items-center justify-between gap-2 rounded-xl bg-background px-3.5">
                <span class="text-primary-text">{{ picker.label }}</span>
                <select
                    v-model="picker.model.value"
                    :name="picker.key"
                    :aria-label="picker.label"
                    :disabled="busy"
                    class="min-h-tap min-w-0 bg-transparent text-right text-base text-accent"
                >
                    <option value="">Choose</option>
                    <option v-for="option in picker.options" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
            </label>
        </form>
    </BottomSheet>
</template>
