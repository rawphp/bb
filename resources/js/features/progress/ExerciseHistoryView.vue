<script setup lang="ts">
import { computed, onMounted, ref, shallowRef } from 'vue';
import type { ExerciseHistoryShowOutput } from '@/contracts/exercise_history_show';
import ExerciseHistoryContent from './ExerciseHistoryContent.vue';
import { read } from '@/app/call';
import { screenRead, type ReadFailure } from '@/app/screenRead';

// One exercise's history under its name (ANA-009). Reads exercise_history_show for
// the exercise in the route; given a payload (/dev/screens/exercise_history_show), shows that.
// Show older sessions reads the next page after next_before and adds its sessions below (EX-002).
const props = defineProps<{ exerciseId?: string; payload?: ExerciseHistoryShowOutput; pending?: boolean }>();

const first = ref<ExerciseHistoryShowOutput | undefined>(props.payload);
// Older pages in the order shown. A new first page drops them: they follow on from the old one.
// Shallow, so a page kept here is the very copy read() returned.
const older = shallowRef<ExerciseHistoryShowOutput[]>([]);
const failure = ref<ReadFailure | null>(null);

const history = computed<ExerciseHistoryShowOutput | undefined>(() => {
    const newest = first.value;
    if (newest === undefined || older.value.length === 0) return newest;

    return {
        ...newest,
        sessions: [...newest.sessions, ...older.value.flatMap((page) => page.sessions)],
        next_before: older.value[older.value.length - 1]!.next_before ?? null,
    };
});

const load = screenRead<ExerciseHistoryShowOutput>(
    'exercise_history_show',
    'History could not be loaded.',
    (data) => {
        failure.value = null;
        first.value = data;
        older.value = [];
    },
    (why) => {
        first.value = undefined;
        older.value = [];
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
    const index = older.value.length;
    // The server's answer replaces the cached copy only while that copy is still shown.
    // One that lands before the copy is placed is placed instead of it.
    let placed: ExerciseHistoryShowOutput | undefined;
    let early: ExerciseHistoryShowOutput | undefined;
    try {
        const page = await read<ExerciseHistoryShowOutput>('exercise_history_show', { exercise_id: props.exerciseId, before }, (fresh) => {
            if (placed === undefined) early = fresh;
            else if (older.value[index] === placed) older.value = older.value.map((shown, at) => (at === index ? fresh : shown));
        });
        // A refresh of the first page that landed meanwhile replaced what this page follows on from.
        if (history.value?.next_before !== before) return;
        placed = early ?? page;
        older.value = [...older.value, placed];
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
