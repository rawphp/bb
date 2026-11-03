<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import ReadState from '@/app/ReadState.vue';
import { read } from '@/app/call';
import { failureMessage } from '@/app/errors';
import { useRead } from '@/app/useRead';
import { useWrite } from '@/app/useWrite';
import type { ExerciseShowOutput } from '@/contracts/exercise_show';
import type { ExercisesShowOutput } from '@/contracts/exercises_show';
import ItemCard from '@/design/ItemCard.vue';
import StatusPill from '@/design/StatusPill.vue';
import { toneClasses } from '@/design/tones';
import CustomExerciseSheet from './CustomExerciseSheet.vue';

// One exercise: a Detail tab and a History tab (EX-002), ported from rp-clone's ExerciseView.swift
// with its ExerciseDetailContent inline. The History tab shows exercise_show's own sessions, so
// the screen reads once. The pinned note (EX-010) and load step (EX-012) are edited here; a
// custom exercise also has Edit (EX-004), which takes its choices from exercises_show.
// `pending` marks the server's details and history as waiting to sync (OFF-008).
type Tab = 'detail' | 'history';
type Choices = Pick<ExercisesShowOutput, 'muscle_groups' | 'equipment' | 'load_types'>;

const props = withDefaults(defineProps<{ exerciseId?: string; payload?: ExerciseShowOutput; pending?: boolean; tab?: string }>(), {
    exerciseId: undefined,
    payload: undefined,
    pending: false,
    tab: 'detail',
});

const TABS: { value: Tab; title: string }[] = [
    { value: 'detail', title: 'Detail' },
    { value: 'history', title: 'History' },
];

const { data, failure, load } = useRead<ExerciseShowOutput>(
    'exercise_show',
    { exercise_id: props.exerciseId ?? props.payload?.id },
    'This exercise could not be loaded.',
    props.payload,
);
const { send, busy, error } = useWrite(load);

const tab = ref<Tab>(props.tab === 'history' ? 'history' : 'detail');
const note = ref('');
const step = ref('');
// The choices Edit last read; the sheet keeps them while it closes.
const choices = ref<Choices | null>(null);
const editOpen = ref(false);

// What the fields show starts from the server's values, and again after every write.
watch(
    data,
    (exercise) => {
        note.value = exercise?.pinned_note ?? '';
        step.value = exercise?.load_step?.step ? String(exercise.load_step.step.value) : '';
    },
    { immediate: true },
);

const typedNote = computed(() => note.value.trim());
const canSaveNote = computed(() => !busy.value && typedNote.value !== '' && typedNote.value !== (data.value?.pinned_note ?? ''));

/** The typed load step: null when blank (the default), undefined when it is not a number. */
const typedStep = computed<number | null | undefined>(() => {
    const cleaned = step.value.trim().replace(',', '.');
    if (cleaned === '') return null;
    const value = Number(cleaned);

    return Number.isFinite(value) ? value : undefined;
});

function saveNote(value: string | null): void {
    if (!data.value) return;
    void send('exercise_note_set', { exercise_id: data.value.id, note: value }, 'The note could not be saved. Try again.');
}

function saveStep(): void {
    const exercise = data.value;
    if (!exercise?.load_step || typedStep.value === undefined) return;
    void send('exercise_load_step_set', { exercise_id: exercise.id, step: typedStep.value, unit: exercise.load_step.unit }, 'The load step could not be saved. Try again.');
}

async function edit(): Promise<void> {
    error.value = null;
    try {
        choices.value = await read<ExercisesShowOutput>('exercises_show', {});
        editOpen.value = true;
    } catch (why) {
        error.value = failureMessage(why, 'The exercise could not be opened for editing. Try again.');
    }
}

const editing = computed(() => {
    const exercise = data.value;

    return exercise ? { id: exercise.id, name: exercise.name, muscle_group: exercise.muscle_group, equipment: exercise.equipment, load_type: exercise.load_type } : null;
});

const facts = computed(() => {
    const exercise = data.value;
    if (!exercise) return [];

    return [
        { label: 'Muscle', value: exercise.muscle_group_text },
        { label: 'Equipment', value: exercise.equipment_text },
        { label: 'Load type', value: exercise.load_type_text },
        ...(exercise.rep_range_text ? [{ label: 'Rep range', value: exercise.rep_range_text }] : []),
    ];
});

const fieldClass = 'w-full rounded-xl bg-background px-3.5 text-base text-primary-text placeholder:text-secondary-text';
const primaryButton = 'min-h-tap flex-1 rounded-full bg-accent px-4 font-medium text-on-accent active:opacity-70 disabled:bg-secondary-text/15 disabled:text-secondary-text';
</script>

<template>
    <main class="bb-screen-content flex flex-col gap-4">
        <template v-if="data">
            <header class="relative flex min-h-tap items-center justify-center px-14">
                <h1 class="truncate text-lg font-semibold">{{ data.name }}</h1>
                <button
                    v-if="data.is_custom"
                    type="button"
                    aria-label="Edit exercise"
                    :disabled="busy"
                    class="absolute right-0 flex min-h-tap min-w-tap items-center justify-center rounded-full px-3 text-accent active:opacity-70"
                    @click="edit"
                >
                    Edit
                </button>
            </header>

            <div role="tablist" aria-label="Section" class="grid grid-cols-2 rounded-full bg-secondary-text/15 p-0.5">
                <button
                    v-for="option in TABS"
                    :key="option.value"
                    type="button"
                    role="tab"
                    :aria-selected="tab === option.value"
                    class="min-h-9 rounded-full text-sm font-medium"
                    :class="tab === option.value ? 'bg-surface text-primary-text shadow-sm ring-1 ring-secondary-text/25' : 'text-secondary-text'"
                    @click="tab = option.value"
                >
                    {{ option.title }}
                </button>
            </div>

            <p v-if="error" class="rounded-2xl p-4 text-sm" :class="toneClasses.warning" role="alert">{{ error }}</p>

            <div v-if="tab === 'detail'" class="flex flex-col gap-3">
                <ItemCard v-if="data.video_url" title="Technique video">
                    <template #icon>
                        <svg viewBox="0 0 24 24" class="size-6">
                            <rect x="2.5" y="5" width="19" height="14" rx="2.5" fill="currentColor" />
                            <path d="M10 9v6l5-3z" fill="var(--color-on-accent)" />
                        </svg>
                    </template>
                    <a
                        :href="data.video_url"
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
                            v-model="note"
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
                                :disabled="!data.pinned_note || busy"
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
                    <div v-if="data.load_step" class="flex flex-col gap-2.5">
                        <label class="flex min-h-tap items-center gap-2 rounded-xl bg-background px-3.5">
                            <input
                                v-model="step"
                                type="text"
                                inputmode="decimal"
                                autocomplete="off"
                                :aria-label="`Load step in ${data.load_step.unit}`"
                                :placeholder="data.load_step.default_text"
                                :disabled="busy"
                                class="min-h-tap w-full bg-transparent text-base tabular-nums text-primary-text placeholder:text-secondary-text focus:outline-none"
                            />
                            <span aria-hidden="true" class="text-secondary-text">{{ data.load_step.unit }}</span>
                        </label>
                        <button type="button" data-step-save :disabled="busy || typedStep === undefined" :class="primaryButton" @click="saveStep">Save</button>
                        <p class="text-sm text-secondary-text">Leave it empty to use the default, {{ data.load_step.default_text }}.</p>
                    </div>
                    <p v-else class="text-secondary-text">Reps only. Bodyweight exercises have no load to step.</p>
                </ItemCard>
            </div>

            <div v-else class="flex flex-col gap-3">
                <p v-if="!data.history.length" class="text-sm text-secondary-text">No sessions logged yet.</p>
                <ItemCard v-for="session in data.history" :key="session.workout_id" data-session :title="session.date_text" :pending="pending">
                    <template #icon>
                        <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round">
                            <rect x="3.5" y="5" width="17" height="15" rx="2.5" />
                            <path d="M3.5 9.5h17M8 3v4M16 3v4" />
                        </svg>
                    </template>
                    <div class="flex flex-col gap-1.5">
                        <div v-for="set in session.sets" :key="set.id" data-set class="flex min-h-8 items-center justify-between gap-2">
                            <span class="text-lg tabular-nums">{{ set.text }}</span>
                            <StatusPill v-if="set.badge" :text="set.badge.text" :tone="set.badge.tone" />
                        </div>
                        <p v-if="session.best_text" class="text-sm text-secondary-text">{{ session.best_text }}</p>
                    </div>
                </ItemCard>
            </div>

            <CustomExerciseSheet
                v-if="choices"
                :open="editOpen"
                :choices="choices"
                :exercise="editing"
                :reload="load"
                @cancel="editOpen = false"
                @saved="editOpen = false"
            />
        </template>
        <ReadState v-else :failure="failure" what="This exercise" @retry="load" />
    </main>
</template>
