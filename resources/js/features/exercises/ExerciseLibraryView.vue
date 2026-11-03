<script setup lang="ts">
import { computed, ref } from 'vue';
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import type { ExercisesShowOutput } from '@/contracts/exercises_show';
import PendingMark from '@/design/PendingMark.vue';
import CustomExerciseSheet from './CustomExerciseSheet.vue';

// Train → Exercises (EX-001, EX-003, EX-013), ported from rp-clone's ExerciseLibraryView.swift:
// search, the muscle and equipment filters, and each row's last-used date and video mark.
// exercises_show sends the whole library, so the search and filters only pick which rows
// to show. `pending` marks the server's last-used dates as waiting to sync (OFF-008).
const props = withDefaults(defineProps<{ payload?: ExercisesShowOutput; pending?: boolean }>(), { pending: false });

const { data, failure, load } = useRead<ExercisesShowOutput>('exercises_show', {}, 'The exercise library could not be loaded.', props.payload);

const search = ref('');
const muscle = ref('');
const equipment = ref('');
const creating = ref(false);

const filtered = computed(() => !!search.value.trim() || !!muscle.value || !!equipment.value);

const rows = computed(() => {
    const text = search.value.trim().toLocaleLowerCase();

    return (data.value?.exercises ?? []).filter(
        (exercise) =>
            (!text || exercise.name.toLocaleLowerCase().includes(text)) &&
            (!muscle.value || exercise.muscle_group === muscle.value) &&
            (!equipment.value || exercise.equipment === equipment.value),
    );
});

const filterClass = (on: boolean) =>
    `min-h-tap appearance-none rounded-full px-4 text-sm font-medium shadow-sm ${on ? 'bg-accent text-on-accent' : 'bg-surface text-primary-text'}`;
</script>

<template>
    <main class="bb-screen-content flex flex-col gap-4">
        <header class="relative flex min-h-tap items-center justify-center">
            <h1 class="text-lg font-semibold">Exercises</h1>
            <button
                type="button"
                aria-label="New exercise"
                class="absolute right-0 flex min-h-tap min-w-tap items-center justify-center rounded-full bg-surface text-primary-text shadow-sm active:opacity-70"
                :disabled="!data"
                @click="creating = true"
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <path d="M12 5v14M5 12h14" />
                </svg>
            </button>
        </header>

        <label class="flex min-h-tap items-center gap-2 rounded-full bg-surface px-4">
            <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5 shrink-0 text-secondary-text" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                <circle cx="11" cy="11" r="7" />
                <path d="m16.5 16.5 4 4" />
            </svg>
            <input
                v-model="search"
                type="search"
                aria-label="Search exercises"
                placeholder="Search exercises"
                maxlength="100"
                enterkeyhint="search"
                autocomplete="off"
                class="min-h-tap w-full bg-transparent text-primary-text placeholder:text-secondary-text focus:outline-none"
            />
        </label>

        <template v-if="data">
            <div class="flex flex-wrap gap-2">
                <select v-model="muscle" aria-label="Muscle filter" :class="filterClass(!!muscle)">
                    <option value="">Muscle: All</option>
                    <option v-for="group in data.muscle_groups" :key="group.value" :value="group.value">{{ group.label }}</option>
                </select>
                <select v-model="equipment" aria-label="Equipment filter" :class="filterClass(!!equipment)">
                    <option value="">Equipment: All</option>
                    <option v-for="kind in data.equipment" :key="kind.value" :value="kind.value">{{ kind.label }}</option>
                </select>
            </div>

            <ul v-if="rows.length" class="flex flex-col overflow-hidden rounded-2xl bg-surface">
                <li v-for="exercise in rows" :key="exercise.id" data-exercise class="border-b border-secondary-text/15 last:border-b-0">
                    <RouterLink :to="`/exercises/${exercise.id}`" class="flex min-h-tap items-center gap-2 px-4 py-2.5 text-primary-text">
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span data-name>{{ exercise.name }}</span>
                            <span class="text-sm text-secondary-text">{{ exercise.detail }}</span>
                            <PendingMark v-if="pending" derived class="text-sm text-secondary-text">{{ exercise.last_used_text }}</PendingMark>
                            <span v-else class="text-sm text-secondary-text">{{ exercise.last_used_text }}</span>
                        </span>
                        <svg v-if="exercise.has_video" role="img" aria-label="Has a technique video" viewBox="0 0 24 24" class="size-6 shrink-0 text-accent">
                            <rect x="2.5" y="5" width="19" height="14" rx="2.5" fill="currentColor" />
                            <path d="M10 9v6l5-3z" fill="var(--color-on-accent)" />
                        </svg>
                        <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4 shrink-0 text-secondary-text" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m9 6 6 6-6 6" />
                        </svg>
                    </RouterLink>
                </li>
            </ul>
            <p v-else class="px-1 text-secondary-text">{{ filtered ? 'No exercises match.' : 'No exercises yet.' }}</p>

            <CustomExerciseSheet :open="creating" :choices="data" :reload="load" @cancel="creating = false" @saved="creating = false" />
        </template>
        <ReadState v-else :failure="failure" what="The exercise library" @retry="load" />
    </main>
</template>
