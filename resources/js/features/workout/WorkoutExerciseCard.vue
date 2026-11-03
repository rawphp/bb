<script setup lang="ts">
import { computed, ref } from 'vue';
import { ulid } from '@/app/ulid';
import type { WorkoutExerciseRemoveInput } from '@/contracts/workout_exercise_remove';
import type { WorkoutExerciseReorderInput } from '@/contracts/workout_exercise_reorder';
import type { WorkoutSetAddInput } from '@/contracts/workout_set_add';
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import ActionMenu, { type ActionMenuItem } from '@/design/ActionMenu.vue';
import { toneClasses } from '@/design/tones';
import { isFeedbackDue } from './feedbackDue';
import WorkoutSetRow from './WorkoutSetRow.vue';
import type { WorkoutSheet, WorkoutWrite } from './workoutWrite';

type Exercise = WorkoutShowOutput['exercises'][number];

// One exercise of the session: its header (WO-002) with the history button and the
// exercise menu (WO-022), its notes, the warm-up guidance on the workout's first exercise
// (WO-024), the column titles and its sets (WO-003). The script holds whether the warm-up
// was dismissed and the first set's typed weight, which later empty sets show (WO-006).
// Ported from rp-clone's WorkoutExerciseCard.
const props = defineProps<{
    exercise: Exercise;
    /** Where it sits in the session, from 0, for Move up and Move down. */
    index: number;
    workout: WorkoutShowOutput;
}>();

const emit = defineEmits<{ send: [write: WorkoutWrite]; open: [sheet: WorkoutSheet] }>();

const editable = computed(() => props.workout.is_editable);
const warmUp = computed(() => (props.workout.warm_up?.workout_exercise_id === props.exercise.id ? props.workout.warm_up : null));
const warmUpDismissed = ref(false);

// FB-001, FB-009: the feedback that is due after this exercise, while it is due or can still change.
const feedback = computed(() =>
    props.workout.feedback.find(
        (entry) => entry.due_after_exercise_id === props.exercise.id && (isFeedbackDue(props.workout, entry) || (entry.is_answered && entry.can_edit)),
    ),
);

// WO-006: the first set's weight as typed, shown on later sets that have none.
const firstTyped = ref<string | null>(null);
const carried = computed(() => {
    if (!props.exercise.carries_first_weight) return null;
    const first = props.exercise.sets[0];
    return firstTyped.value ?? (first?.weight ? String(first.weight.value) : null);
});

// Swap and the notes open their sheets through the screen.
const items = computed<ActionMenuItem[]>(() => [
    ...props.workout.add_set_choices.map((choice) => ({ key: `add:${choice.value}`, title: `Add set: ${choice.label}`, detail: choice.note })),
    { key: 'swap', title: 'Swap exercise' },
    { key: 'pin', title: 'Pin note' },
    { key: 'note', title: 'Add note' },
    { key: 'up', title: 'Move up', disabled: !props.exercise.can_move_up },
    { key: 'down', title: 'Move down', disabled: !props.exercise.can_move_down },
    { key: 'remove', title: 'Remove exercise', destructive: true },
]);

function reorder(position: number, verb: string): void {
    const input: WorkoutExerciseReorderInput = { workout_exercise_id: props.exercise.id, position, remaining_weeks: false };
    emit('send', { name: 'workout_exercise_reorder', input: { ...input }, label: `Moved ${props.exercise.name} ${verb}` });
}

function choose(key: string): void {
    if (key.startsWith('add:')) {
        const choice = props.workout.add_set_choices.find((found) => `add:${found.value}` === key);
        if (!choice) return;
        const id = ulid();
        const input: WorkoutSetAddInput = {
            set_id: id,
            workout_exercise_id: props.exercise.id,
            after_set_id: props.exercise.sets.at(-1)?.id ?? null,
            set_type: choice.value as WorkoutSetAddInput['set_type'],
        };
        emit('send', { name: 'workout_set_add', input: { ...input }, label: `Added a ${choice.label} set to ${props.exercise.name}`, creates: [id] });
    } else if (key === 'swap') {
        emit('open', { name: 'swap', exerciseId: props.exercise.id });
    } else if (key === 'pin' || key === 'note') {
        emit('open', { name: 'note', exerciseId: props.exercise.id, pinned: key === 'pin' });
    } else if (key === 'up') {
        // Positions count from 1: one place up is the index it has now.
        reorder(props.index, 'up');
    } else if (key === 'down') {
        reorder(props.index + 2, 'down');
    } else if (key === 'remove') {
        const input: WorkoutExerciseRemoveInput = { workout_exercise_id: props.exercise.id };
        emit('send', {
            name: 'workout_exercise_remove',
            input: { ...input },
            label: `Removed ${props.exercise.name}`,
            confirm: { title: `Remove ${props.exercise.name}?`, message: 'Its sets are deleted.', action: 'Remove exercise' },
        });
    }
}

const iconButton = 'flex min-h-tap min-w-tap shrink-0 items-center justify-center text-accent';
</script>

<template>
    <section class="flex flex-col gap-2 rounded-2xl bg-surface p-3" :aria-label="exercise.name" :data-exercise="exercise.id">
        <header class="flex items-start gap-1">
            <div class="flex min-h-tap min-w-0 flex-1 flex-col justify-center">
                <h2 class="font-semibold text-primary-text">{{ exercise.name }}</h2>
                <p class="text-sm text-secondary-text">{{ exercise.detail }}</p>
            </div>
            <RouterLink :to="`/progress/exercises/${exercise.exercise_id}`" :class="iconButton" :aria-label="`${exercise.name} history`" data-test="history">
                <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true">
                    <path d="M3.5 12a8.5 8.5 0 1 0 2.5-6M3.5 4v4h4M12 7.5V12l3 2" />
                </svg>
            </RouterLink>
            <ActionMenu :label="`${exercise.name} menu`" :items="items" :disabled="!editable" data-test="exercise-menu" @select="choose">
                <svg viewBox="0 0 24 24" class="size-6 text-accent" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <circle cx="12" cy="12" r="9.25" />
                    <path d="M8 12h.01M12 12h.01M16 12h.01" stroke-linecap="round" stroke-width="2.5" />
                </svg>
            </ActionMenu>
        </header>

        <p v-if="exercise.pinned_note" class="flex gap-2 rounded-[10px] bg-background p-2 text-sm text-primary-text" data-test="pinned-note">
            <svg viewBox="0 0 24 24" class="mt-0.5 size-4 shrink-0" fill="currentColor" aria-label="Pinned note" role="img">
                <path d="M15 3l6 6-3 1-4 4 1 5-2 2-4-5-5 5-1-1 5-5-5-4 2-2 5 1 4-4z" />
            </svg>
            <span>{{ exercise.pinned_note }}</span>
        </p>
        <p v-if="exercise.recovery_note" class="rounded-[10px] p-2 text-sm" :class="toneClasses[exercise.recovery_note.tone]" data-test="recovery-note">
            {{ exercise.recovery_note.text }}
        </p>
        <div v-if="exercise.swap_suggestion" class="flex items-center gap-2 rounded-[10px] pl-2" :class="toneClasses[exercise.swap_suggestion.tone]" data-test="swap-suggestion">
            <p class="flex-1 text-sm">{{ exercise.swap_suggestion.text }}</p>
            <button
                type="button"
                class="min-h-tap min-w-tap px-2 text-sm font-semibold disabled:opacity-50"
                :disabled="!editable"
                :aria-label="`Swap ${exercise.name}`"
                @click="emit('open', { name: 'swap', exerciseId: exercise.id })"
            >
                Swap
            </button>
        </div>
        <p v-if="exercise.session_note" class="text-sm text-secondary-text" data-test="session-note">{{ exercise.session_note }}</p>

        <div v-if="warmUp && !warmUpDismissed" class="rounded-xl px-2.5 pb-2" :class="toneClasses.info" data-test="warm-up">
            <div class="flex items-center">
                <h3 class="flex-1 text-sm font-semibold">{{ warmUp.title }}</h3>
                <button type="button" class="min-h-tap min-w-tap text-sm font-semibold" @click="warmUpDismissed = true">Got it</button>
            </div>
            <ol class="list-inside list-decimal text-sm">
                <li v-for="step in warmUp.steps" :key="step.text">{{ step.text }}</li>
            </ol>
            <p class="mt-1 text-sm font-semibold">{{ warmUp.note }}</p>
        </div>

        <div class="flex items-end gap-1 text-xs font-semibold text-secondary-text" aria-hidden="true">
            <span class="w-tap shrink-0" />
            <span class="w-[34px] shrink-0 text-center">Set</span>
            <span class="flex-1 text-center">{{ exercise.weight_label }}</span>
            <span class="flex-1 text-center">Reps</span>
            <span class="w-tap shrink-0" />
            <span class="w-tap shrink-0 text-center">Log</span>
        </div>
        <WorkoutSetRow
            v-for="set in exercise.sets"
            :key="set.id"
            :set="set"
            :exercise="exercise"
            :unit="workout.weight_unit"
            :editable="editable"
            :carried-weight="set.number === 1 ? null : carried"
            @send="(write) => emit('send', write)"
            @open="(sheet) => emit('open', sheet)"
            @weight-typed="(text) => (firstTyped = text)"
        />
        <!-- FB-001, FB-009: the muscle's feedback, again after it was cancelled or answered. -->
        <button
            v-if="feedback"
            type="button"
            class="flex min-h-tap w-full items-center justify-center gap-2 rounded-xl border text-sm font-semibold"
            :class="feedback.is_answered ? 'border-accent text-accent' : 'border-tone-warning text-tone-warning'"
            data-test="feedback"
            @click="emit('open', { name: 'feedback', muscle: feedback.muscle_group })"
        >
            {{ feedback.is_answered ? `${feedback.title} feedback` : `${feedback.title} feedback needed` }}
        </button>
    </section>
</template>
