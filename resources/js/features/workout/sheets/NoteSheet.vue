<script setup lang="ts">
import { computed, ref } from 'vue';
import type { ExerciseNoteSetInput } from '@/contracts/exercise_note_set';
import type { WorkoutExerciseNoteSetInput } from '@/contracts/workout_exercise_note_set';
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import BottomSheet from '@/design/BottomSheet.vue';
import type { WorkoutWrite } from '../workoutWrite';

// Pin a note to the exercise, shown every time it appears (EX-010), or add one kept with
// this session (WO-022). Empty text clears the note. The script holds the text (VIEW-002).
// Ported from rp-clone's NoteSheet.
const props = defineProps<{ payload?: WorkoutShowOutput; /** The workout exercise's id. */ exerciseId: string; pinned?: boolean }>();

const emit = defineEmits<{ send: [write: WorkoutWrite]; close: [] }>();

const exercise = computed(() => props.payload?.exercises.find((found) => found.id === props.exerciseId));
const text = ref((props.pinned ? exercise.value?.pinned_note : exercise.value?.session_note) ?? '');

function save(): void {
    const current = exercise.value;
    if (!current) return;
    const note = text.value.trim() === '' ? null : text.value.trim();
    if (props.pinned) {
        const input: ExerciseNoteSetInput = { exercise_id: current.exercise_id, note };
        emit('send', { name: 'exercise_note_set', input: { ...input }, label: `${note === null ? 'Cleared the pinned note on' : 'Pinned a note on'} ${current.name}` });
    } else {
        const input: WorkoutExerciseNoteSetInput = { workout_exercise_id: current.id, note };
        emit('send', { name: 'workout_exercise_note_set', input: { ...input }, label: `${note === null ? 'Cleared the note on' : 'Added a note to'} ${current.name}` });
    }
    emit('close');
}
</script>

<template>
    <BottomSheet :open="true" :title="pinned ? 'Pin note' : 'Add note'" :heights="['half']" @cancel="emit('close')" @save="save">
        <div class="flex flex-col gap-2.5 p-4">
            <p class="text-sm text-secondary-text">{{ pinned ? `Shows every time ${exercise?.name ?? 'this exercise'} appears.` : 'Kept with this session.' }}</p>
            <textarea v-model="text" aria-label="Note" rows="5" class="min-h-32 w-full rounded-xl bg-background p-3 text-primary-text outline-none focus:ring-2 focus:ring-accent" />
        </div>
    </BottomSheet>
</template>
