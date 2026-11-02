<script setup lang="ts">
import { onMounted, ref } from 'vue';
import type { ProgressShowOutput } from '@/contracts/progress_show';
import OfflineNotice from '@/design/OfflineNotice.vue';
import Skeleton from '@/design/Skeleton.vue';
import { screenRead, type ReadFailure } from './screenRead';

// Exercises with a logged set, most recent first (ANA-009), from progress_show's list.
const exercises = ref<ProgressShowOutput['exercises'] | undefined>();
const failure = ref<ReadFailure | null>(null);

const load = screenRead<ProgressShowOutput>(
    'progress_show',
    'Exercises could not be loaded.',
    (data) => {
        failure.value = null;
        exercises.value = data.exercises;
    },
    (why) => {
        exercises.value = undefined;
        failure.value = why;
    },
);

onMounted(() => void load());
</script>

<template>
    <main class="bb-screen-content flex flex-col gap-4">
        <h1 class="flex min-h-tap items-center justify-center text-lg font-semibold">Exercise history</h1>
        <OfflineNotice v-if="failure?.offline" needs="Exercise history needs a connection." />
        <p v-else-if="failure" role="alert" class="rounded-2xl bg-surface p-4 text-tone-warning">{{ failure.error }}</p>
        <Skeleton v-else-if="!exercises" shape="row" :count="4" label="Loading exercises" />
        <p v-else-if="!exercises.length" class="text-sm text-secondary-text">No exercises logged yet.</p>
        <ul v-else class="flex flex-col overflow-hidden rounded-2xl bg-surface">
            <li v-for="exercise in exercises" :key="exercise.id" class="border-b border-secondary-text/15 last:border-b-0">
                <RouterLink :to="`/progress/exercises/${exercise.id}`" class="flex min-h-tap items-center justify-between gap-2 px-4 py-2">
                    <span class="flex flex-col">
                        <span>{{ exercise.name }}</span>
                        <span class="text-sm text-secondary-text">{{ exercise.muscle }}</span>
                    </span>
                    <svg viewBox="0 0 24 24" class="size-4 shrink-0 text-secondary-text" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                        <path d="M9 5l7 7-7 7" />
                    </svg>
                </RouterLink>
            </li>
        </ul>
    </main>
</template>
