<script setup lang="ts">
import { PENDING_DIM } from '../types';
import type { Build, BuildSlot } from './types';

// One slot: its muscle, the sets, an exercise picker and Delete (MESO-013, MESO-014).
// Ported from rp-clone's SlotRowView. The words on both pickers come from the server.
// `pending` dims the server's sets text while a change waits to sync (OFF-008); the
// step flow carries the one "Waiting to sync" mark.
const props = withDefaults(
    defineProps<{
        entry: BuildSlot;
        setsOptions: Build['sets_options'];
        exercises: Build['exercise_options'][number]['exercises'];
        disabled?: boolean;
        pending?: boolean;
    }>(),
    { disabled: false, pending: false },
);

const emit = defineEmits<{ sets: [sets: number]; exercise: [id: string | null]; delete: [] }>();

// A picker always shows what the server last said: it goes back to it at once, so a
// failed save leaves nothing picked that was never saved, and the same pick sends again.
function chooseSets(event: Event): void {
    const select = event.target as HTMLSelectElement;
    const picked = Number(select.value);
    select.value = String(props.entry.sets);
    if (picked !== props.entry.sets) emit('sets', picked);
}

function chooseExercise(event: Event): void {
    const select = event.target as HTMLSelectElement;
    const picked = select.value === '' ? null : select.value;
    select.value = props.entry.exercise_id ?? '';
    if (picked !== (props.entry.exercise_id ?? null)) emit('exercise', picked);
}
</script>

<template>
    <div data-test="slot" class="flex flex-col gap-1 py-2">
        <div class="flex min-h-tap items-center gap-2">
            <span class="grow text-sm font-semibold text-secondary-text">{{ entry.muscle_title }}</span>
            <span class="relative flex min-h-tap min-w-20 items-center justify-end gap-1 text-accent">
                <select
                    class="absolute inset-0 cursor-pointer opacity-0 disabled:cursor-default"
                    :aria-label="`${entry.muscle_title} sets`"
                    :disabled="disabled"
                    @change="chooseSets"
                >
                    <option v-for="option in setsOptions" :key="option.value" :value="option.value" :selected="option.value === entry.sets">{{ option.text }}</option>
                </select>
                <span aria-hidden="true" data-test="sets" :class="[pending ? PENDING_DIM : '', disabled ? 'opacity-50' : '']">{{ entry.sets_text }}</span>
                <svg viewBox="0 0 12 16" class="size-3" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 6l3-3 3 3M3 10l3 3 3-3" />
                </svg>
            </span>
            <button
                type="button"
                class="flex min-h-tap min-w-tap items-center justify-center rounded-full text-tone-warning disabled:opacity-50"
                :aria-label="`Delete ${entry.muscle_title}`"
                :disabled="disabled"
                @click="emit('delete')"
            >
                <svg viewBox="0 0 24 24" class="size-5" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" />
                </svg>
            </button>
        </div>
        <span class="relative flex min-h-tap items-center gap-2">
            <select
                class="absolute inset-0 cursor-pointer opacity-0 disabled:cursor-default"
                :aria-label="`${entry.muscle_title} exercise`"
                :disabled="disabled"
                @change="chooseExercise"
            >
                <option value="" :selected="!entry.exercise_id">None</option>
                <option v-for="exercise in exercises" :key="exercise.id" :value="exercise.id" :selected="exercise.id === entry.exercise_id">{{ exercise.name }}</option>
            </select>
            <span aria-hidden="true" class="grow" :class="[entry.exercise_name ? 'text-primary-text' : 'text-secondary-text', disabled ? 'opacity-50' : '']">{{ entry.exercise_name ?? 'Choose an exercise' }}</span>
            <svg viewBox="0 0 12 16" class="size-3 text-secondary-text" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 6l3-3 3 3M3 10l3 3 3-3" />
            </svg>
        </span>
    </div>
</template>
