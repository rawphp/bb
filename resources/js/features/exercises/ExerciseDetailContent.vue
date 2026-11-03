<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useWrite } from '@/app/useWrite';
import type { ExerciseShowOutput } from '@/contracts/exercise_show';
import ItemCard from '@/design/ItemCard.vue';
import { toneClasses } from '@/design/tones';

// The Detail tab of the exercise screen (EX-002), ported from rp-clone's ExerciseDetailContent:
// the video, the details, the pinned note (EX-010) and the load step (EX-012). `reload` is the
// screen's read, which useWrite runs after each save. A field the user has typed in keeps what
// they typed when the exercise is read again; the field just saved shows the server's answer.
// `pending` marks the server's details as waiting to sync (OFF-008).
const props = withDefaults(
    defineProps<{
        exercise: ExerciseShowOutput;
        reload: (options?: { fresh?: boolean }) => Promise<void>;
        pending?: boolean;
    }>(),
    { pending: false },
);

const { send, busy, error } = useWrite((options) => props.reload(options));

type Field = 'note' | 'step';

/** What each field shows for an exercise when nothing has been typed. */
function stored(exercise: ExerciseShowOutput): Record<Field, string> {
    return { note: exercise.pinned_note ?? '', step: exercise.load_step?.step ? String(exercise.load_step.step.value) : '' };
}

const typed = ref<Record<Field, string>>(stored(props.exercise));
// What the fields were last filled with, so an edited field can be told apart from one left alone.
let filled = stored(props.exercise);

function fill(exercise: ExerciseShowOutput, force?: Field): void {
    const next = stored(exercise);
    for (const field of ['note', 'step'] as const) {
        if (field === force || typed.value[field] === filled[field]) typed.value[field] = next[field];
    }
    filled = next;
}

// flush 'sync': a save's re-read has filled the fields by the time the save resolves.
watch(
    () => props.exercise,
    (exercise) => fill(exercise),
    { flush: 'sync' },
);

const typedNote = computed(() => typed.value.note.trim());
const canSaveNote = computed(() => !busy.value && typedNote.value !== '' && typedNote.value !== (props.exercise.pinned_note ?? ''));

/** The typed load step: null when blank (the default), undefined when it is not a number. */
const typedStep = computed<number | null | undefined>(() => {
    const cleaned = typed.value.step.trim().replace(',', '.');
    if (cleaned === '') return null;
    const value = Number(cleaned);

    return Number.isFinite(value) ? value : undefined;
});

async function saveNote(value: string | null): Promise<void> {
    const outcome = await send('exercise_note_set', { exercise_id: props.exercise.id, note: value }, 'The note could not be saved. Try again.');
    if (outcome === 'done') fill(props.exercise, 'note');
}

async function saveStep(): Promise<void> {
    const loadStep = props.exercise.load_step;
    if (!loadStep || typedStep.value === undefined) return;
    const outcome = await send(
        'exercise_load_step_set',
        { exercise_id: props.exercise.id, step: typedStep.value, unit: loadStep.unit },
        'The load step could not be saved. Try again.',
    );
    if (outcome === 'done') fill(props.exercise, 'step');
}

const facts = computed(() => [
    { label: 'Muscle', value: props.exercise.muscle_group_text },
    { label: 'Equipment', value: props.exercise.equipment_text },
    { label: 'Load type', value: props.exercise.load_type_text },
    ...(props.exercise.rep_range_text ? [{ label: 'Rep range', value: props.exercise.rep_range_text }] : []),
]);

const fieldClass = 'w-full rounded-xl bg-background px-3.5 text-base text-primary-text placeholder:text-secondary-text';
const primaryButton = 'min-h-tap flex-1 rounded-full bg-accent px-4 font-medium text-on-accent active:opacity-70 disabled:bg-secondary-text/15 disabled:text-secondary-text';
</script>

<template>
    <div class="flex flex-col gap-3">
        <p v-if="error" class="rounded-2xl p-4 text-sm" :class="toneClasses.warning" role="alert">{{ error }}</p>
        <ItemCard v-if="exercise.video_url" title="Technique video">
            <template #icon>
                <svg viewBox="0 0 24 24" class="size-6">
                    <rect x="2.5" y="5" width="19" height="14" rx="2.5" fill="currentColor" />
                    <path d="M10 9v6l5-3z" fill="var(--color-on-accent)" />
                </svg>
            </template>
            <a
                :href="exercise.video_url"
                target="_blank"
                rel="noopener noreferrer"
                aria-description="Opens the video outside the app"
                class="flex min-h-tap items-center gap-2 text-accent"
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3.5" y="3.5" width="17" height="17" rx="3" />
                    <path d="M9.5 14.5 15 9M10.5 9H15v4.5" />
                </svg>
                Watch on YouTube
            </a>
        </ItemCard>

        <ItemCard title="Details" data-card="details" :pending="pending">
            <template #icon>
                <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <path d="M3 12h18M6 8v8M9 9v6M15 9v6M18 8v8" />
                </svg>
            </template>
            <div class="flex flex-col">
                <p v-for="fact in facts" :key="fact.label" data-fact class="flex min-h-tap items-center justify-between gap-2">
                    <span class="text-secondary-text">{{ fact.label }}</span>
                    <span class="text-right">{{ fact.value }}</span>
                </p>
            </div>
        </ItemCard>

        <ItemCard title="Pinned note">
            <template #icon>
                <svg viewBox="0 0 24 24" class="size-6" fill="currentColor">
                    <path d="M8 3h8l-1.5 6 3.5 3.5V14h-5v7l-1 1-1-1v-7H6v-1.5L9.5 9z" />
                </svg>
            </template>
            <div class="flex flex-col gap-2.5">
                <textarea
                    v-model="typed.note"
                    aria-label="Pinned note"
                    rows="3"
                    maxlength="1000"
                    :disabled="busy"
                    class="min-h-24 resize-none py-2.5"
                    :class="fieldClass"
                />
                <div class="flex gap-3">
                    <button type="button" data-note-save :disabled="!canSaveNote" :class="primaryButton" @click="saveNote(typedNote)">Save</button>
                    <button
                        type="button"
                        data-note-clear
                        :disabled="!exercise.pinned_note || busy"
                        class="min-h-tap flex-1 rounded-full bg-secondary-text/15 px-4 font-medium text-tone-warning active:opacity-70 disabled:text-secondary-text"
                        @click="saveNote(null)"
                    >
                        Clear
                    </button>
                </div>
                <p class="text-sm text-secondary-text">It shows every time this exercise appears.</p>
            </div>
        </ItemCard>

        <ItemCard title="Load step">
            <template #icon>
                <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round">
                    <circle cx="12" cy="5.5" r="2" />
                    <path d="M7.5 8.5h9L20 20H4z" />
                </svg>
            </template>
            <div v-if="exercise.load_step" class="flex flex-col gap-2.5">
                <label class="flex min-h-tap items-center gap-2 rounded-xl bg-background px-3.5">
                    <input
                        v-model="typed.step"
                        type="text"
                        inputmode="decimal"
                        autocomplete="off"
                        :aria-label="`Load step in ${exercise.load_step.unit}`"
                        :placeholder="exercise.load_step.default_text"
                        :disabled="busy"
                        class="min-h-tap w-full bg-transparent text-base tabular-nums text-primary-text placeholder:text-secondary-text focus:outline-none"
                    />
                    <!-- The placeholder (the default) already names the unit. -->
                    <span v-if="typed.step" aria-hidden="true" class="text-secondary-text">{{ exercise.load_step.unit }}</span>
                </label>
                <button type="button" data-step-save :disabled="busy || typedStep === undefined" :class="primaryButton" @click="saveStep">Save</button>
                <p class="text-sm text-secondary-text">Leave it empty to use the default, {{ exercise.load_step.default_text }}.</p>
            </div>
            <p v-else class="text-secondary-text">Reps only. Bodyweight exercises have no load to step.</p>
        </ItemCard>
    </div>
</template>
