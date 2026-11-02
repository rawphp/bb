<script setup lang="ts">
import { onMounted, ref } from 'vue';
import type { ExerciseHistoryShowOutput } from '@/contracts/exercise_history_show';
import ExerciseHistoryContent from './ExerciseHistoryContent.vue';
import { screenRead, type ReadFailure } from './screenRead';

// One exercise's history under its name (ANA-009). Reads exercise_history_show for
// the exercise in the route; given a payload (/dev/screens/exercise_history_show), shows that.
const props = defineProps<{ exerciseId?: string; payload?: ExerciseHistoryShowOutput; pending?: boolean }>();

const history = ref<ExerciseHistoryShowOutput | undefined>(props.payload);
const failure = ref<ReadFailure | null>(null);

const load = screenRead<ExerciseHistoryShowOutput>(
    'exercise_history_show',
    'History could not be loaded.',
    (data) => {
        failure.value = null;
        history.value = data;
    },
    (why) => {
        history.value = undefined;
        failure.value = why;
    },
);

onMounted(() => {
    if (!history.value && props.exerciseId) void load({ exercise_id: props.exerciseId });
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
