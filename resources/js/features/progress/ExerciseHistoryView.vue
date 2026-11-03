<script setup lang="ts">
import { onMounted, ref } from 'vue';
import type { ExerciseHistoryShowOutput } from '@/contracts/exercise_history_show';
import ExerciseHistoryContent from './ExerciseHistoryContent.vue';
import { read } from '@/app/call';
import { screenRead, type ReadFailure } from '@/app/screenRead';

// One exercise's history under its name (ANA-009). Reads exercise_history_show for
// the exercise in the route; given a payload (/dev/screens/exercise_history_show), shows that.
// Show older sessions reads the next page after next_before and adds its sessions below (EX-002).
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

const loadingMore = ref(false);
const moreError = ref<string | null>(null);

async function more() {
    const before = history.value?.next_before;
    if (!props.exerciseId || !before || loadingMore.value) return;
    loadingMore.value = true;
    moreError.value = null;
    try {
        const page = await read<ExerciseHistoryShowOutput>('exercise_history_show', { exercise_id: props.exerciseId, before });
        // A refresh of the first page that landed meanwhile replaced what this page follows on from.
        const shown = history.value;
        if (shown?.next_before !== before) return;
        history.value = { ...shown, sessions: [...shown.sessions, ...page.sessions], next_before: page.next_before ?? null };
    } catch {
        moreError.value = 'Older sessions could not be loaded.';
    } finally {
        loadingMore.value = false;
    }
}

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
        <ExerciseHistoryContent
            :payload="history"
            :error="failure?.error"
            :offline="failure?.offline"
            :pending="pending"
            :loading-more="loadingMore"
            :more-error="moreError"
            @more="more"
        />
    </main>
</template>
