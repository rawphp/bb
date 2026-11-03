<script setup lang="ts">
import { computed, ref } from 'vue';
import ReadState from '@/app/ReadState.vue';
import { read } from '@/app/call';
import { failureMessage } from '@/app/errors';
import { useRead } from '@/app/useRead';
import type { ExerciseShowOutput } from '@/contracts/exercise_show';
import type { ExercisesShowOutput } from '@/contracts/exercises_show';
import ItemCard from '@/design/ItemCard.vue';
import StatusPill from '@/design/StatusPill.vue';
import { toneClasses } from '@/design/tones';
import CustomExerciseSheet, { type Choices } from './CustomExerciseSheet.vue';
import ExerciseDetailContent from './ExerciseDetailContent.vue';

// One exercise: a Detail tab (ExerciseDetailContent) and a History tab (EX-002), ported from
// rp-clone's ExerciseView.swift. The History tab shows exercise_show's own sessions, so the
// screen reads once. A custom exercise also has Edit (EX-004), which takes its choices from
// exercises_show. `pending` marks the server's details and history as waiting to sync (OFF-008).
type Tab = 'detail' | 'history';

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

const tab = ref<Tab>(props.tab === 'history' ? 'history' : 'detail');
// The choices Edit last read; the sheet keeps them while it closes.
const choices = ref<Choices | null>(null);
const editOpen = ref(false);
const editError = ref<string | null>(null);

async function edit(): Promise<void> {
    editError.value = null;
    try {
        choices.value = await read<ExercisesShowOutput>('exercises_show', {});
        editOpen.value = true;
    } catch (why) {
        editError.value = failureMessage(why, 'The exercise could not be opened for editing. Try again.');
    }
}

const editing = computed(() => {
    const exercise = data.value;

    return exercise ? { id: exercise.id, name: exercise.name, muscle_group: exercise.muscle_group, equipment: exercise.equipment, load_type: exercise.load_type } : null;
});
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

            <p v-if="editError" class="rounded-2xl p-4 text-sm" :class="toneClasses.warning" role="alert">{{ editError }}</p>

            <!-- v-show: Detail keeps what was typed, and a save in flight, while History is open. -->
            <ExerciseDetailContent v-show="tab === 'detail'" :exercise="data" :reload="load" :pending="pending" />

            <div v-show="tab === 'history'" class="flex flex-col gap-3">
                <p v-if="!data.history.length" data-no-sessions class="text-sm text-secondary-text">No sessions logged yet.</p>
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
