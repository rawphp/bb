<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { read } from '@/app/call';
import type { ExerciseHistoryShowOutput } from '@/contracts/exercise_history_show';
import ExerciseHistoryContent from './ExerciseHistoryContent.vue';
import { readFailure, type ReadFailure } from './readFailure';

// One exercise's history under its name (ANA-009). Reads exercise_history_show for
// the exercise in the route; given a payload (/dev/screens/exercise_history_show), shows that.
const props = defineProps<{ exerciseId?: string; payload?: ExerciseHistoryShowOutput; pending?: boolean }>();

const history = ref<ExerciseHistoryShowOutput | undefined>(props.payload);
const failure = ref<ReadFailure | null>(null);

onMounted(async () => {
    if (history.value || !props.exerciseId) return;
    let settled = false;
    const fail = (error: unknown) => {
        settled = true;
        history.value = undefined;
        failure.value = readFailure(error, 'History could not be loaded.');
    };
    const show = (data: ExerciseHistoryShowOutput) => {
        settled = true;
        failure.value = null;
        history.value = data;
    };
    try {
        const data = await read<ExerciseHistoryShowOutput>('exercise_history_show', { exercise_id: props.exerciseId }, show, fail);
        if (!settled) show(data);
    } catch (error) {
        fail(error);
    }
});
</script>

<template>
    <main class="bb-screen-content flex flex-col gap-4">
        <header class="flex min-h-tap flex-col justify-center text-center">
            <h1 class="text-lg font-semibold">{{ history?.title ?? 'Exercise history' }}</h1>
            <p v-if="history" class="text-sm text-secondary-text">{{ history.subtitle }}</p>
        </header>
        <ExerciseHistoryContent :payload="history" :error="failure?.error" :offline="failure?.offline" :pending="pending" />
    </main>
</template>
