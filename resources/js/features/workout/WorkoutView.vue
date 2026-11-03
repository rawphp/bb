<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { write } from '@/app/call';
import { writeFailureMessage } from '@/app/errors';
import { isPending, pendingInput } from '@/app/outbox';
import ReadState from '@/app/ReadState.vue';
import { useScreenTop } from '@/app/screenTop';
import { useRead } from '@/app/useRead';
import type { WorkoutFinishInput } from '@/contracts/workout_finish';
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import type { WorkoutStartInput } from '@/contracts/workout_start';
import ActionMenu, { type ActionMenuItem } from '@/design/ActionMenu.vue';
import BottomSheet from '@/design/BottomSheet.vue';
import PendingMark from '@/design/PendingMark.vue';
import { toneClasses } from '@/design/tones';
import { showWorkout } from './activeWorkout';
import { holdWakeLock, leaveWakeLock, releaseWakeLock } from './wakeLock';
import WorkoutExerciseCard from './WorkoutExerciseCard.vue';
import type { WorkoutWrite } from './workoutWrite';

// The workout screen for the gym (WO-001 to WO-025): the session title, each exercise with
// its sets, the post-workout meal (WO-009) and Finish (WO-010). Sized for the gym (UI-020):
// every control is at least 48 px, weight and reps open the number pads, and nothing is a
// swipe. Every change goes through the outbox (OFF-006), so a whole session works offline;
// the first one starts the session (workout_start) when the server says it has not started.
// While a change waits, the server's numbers show as pending (OFF-008). While the session is
// under way the screen holds a wake lock (PWA-030) and keeps the bar above the tabs current
// (NAV-004). The script holds which question is open and what failed to store (VIEW-002).
// The day menu, help and the workout sheets come with V_WO2. Ported from rp-clone's WorkoutView.
const props = withDefaults(defineProps<{ payload?: WorkoutShowOutput; mesocycleId?: string; week?: number; day?: number; pending?: boolean }>(), {
    mesocycleId: '',
    week: 1,
    day: 1,
    pending: false,
});

const router = useRouter();
const { data, failure, load } = useRead<WorkoutShowOutput>(
    'workout_show',
    { mesocycle_id: props.mesocycleId, week: props.week, day: props.day },
    'This workout could not be loaded.',
    props.payload,
);
const pending = computed(() => props.pending || isPending('workout_show'));
const path = computed(() => `/workouts/${data.value?.mesocycle_id ?? props.mesocycleId}/${props.week}/${props.day}`);

// PWA-033: a short tap where the device has one (Android); iOS has none and gets nothing.
function haptic(): void {
    if (typeof navigator !== 'undefined' && 'vibrate' in navigator) navigator.vibrate(10);
}

// Writes are stored one after another, so a field's change is in the outbox before the Log
// tap that follows it, and the session's start before both.
const error = ref<string | null>(null);
let chain: Promise<void> = Promise.resolve();

async function store(change: WorkoutWrite): Promise<boolean> {
    const workout = data.value;
    if (!workout) return false;
    error.value = null;
    try {
        if (workout.status === 'not_started' && !pendingInput<WorkoutStartInput>('workout_start', (input) => input.workout_id === workout.workout_id)) {
            const start: WorkoutStartInput = { workout_id: workout.workout_id };
            await write('workout_start', { ...start }, { label: `Started ${workout.day_label}` });
        }
        await write(change.name, change.input, { label: change.label, creates: change.creates });
        if (change.haptic) haptic();
        return true;
    } catch (failed) {
        error.value = writeFailureMessage(failed, 'The change could not be saved.');
        return false;
    }
}

function queue(change: WorkoutWrite): Promise<boolean> {
    const stored = chain.then(() => store(change));
    chain = stored.then(() => undefined);
    return stored;
}

// A change that can't be undone asks first.
const confirm = ref<WorkoutWrite | null>(null);

function send(change: WorkoutWrite): void {
    if (change.confirm) confirm.value = change;
    else void queue(change);
}

async function confirmed(): Promise<void> {
    const asked = confirm.value;
    if (asked && (await queue(asked))) confirm.value = null;
}

// WO-010: Finish records the end; the server checks every set is done and feedback is in (WO-040).
function finish(): void {
    const workout = data.value;
    if (!workout) return;
    const input: WorkoutFinishInput = { workout_id: workout.workout_id };
    void queue({ name: 'workout_finish', input: { ...input }, label: `Finished ${workout.day_label}`, haptic: true }).then((done) => {
        if (done) releaseWakeLock(workout.workout_id);
    });
}

// OFF-021: a start or finish waiting in the outbox counts, so an offline session behaves as
// it will once synced. While changes wait, can_finish is from before them, and the server
// decides on the finish itself (workout_finish has the last word); a finish already waiting
// is not sent again.
const waiting = (name: string) => {
    const workout = data.value;
    return workout !== undefined && pendingInput<WorkoutFinishInput>(name, (input) => input.workout_id === workout.workout_id) !== undefined;
};
const finishWaiting = computed(() => waiting('workout_finish'));
const canFinish = computed(() => !finishWaiting.value && (data.value?.can_finish === true || pending.value));

// WO-008: an earlier session of the mesocycle, opened in place of this one.
const lookBack = computed<ActionMenuItem[]>(() =>
    (data.value?.look_back ?? []).map((entry) => ({ key: `${entry.week}/${entry.day}`, title: entry.title, detail: entry.status.text })),
);

function openEarlier(key: string): void {
    if (data.value) void router.push(`/workouts/${data.value.mesocycle_id}/${key}`);
}

// PWA-030 and NAV-004: what the session's state means for the wake lock and the bar.
// A /dev/screens fixture is not the user's session: it leaves both alone.
const top = useScreenTop();
const live = props.payload === undefined;
const onShow = computed(() => top?.value ?? true);
watch(
    [() => data.value?.status, () => data.value?.active_bar_text, onShow, () => waiting('workout_start'), finishWaiting],
    () => {
        const workout = data.value;
        if (!workout || !live) return;
        showWorkout(workout.workout_id, path.value, workout.active_bar_text);
        const id = workout.workout_id;
        const underWay = workout.status === 'in_progress' || (workout.status === 'not_started' && waiting('workout_start'));
        if (workout.status === 'finished' || workout.status === 'skipped' || finishWaiting.value) releaseWakeLock(id);
        else if (underWay && onShow.value) holdWakeLock(id);
        else if (underWay) leaveWakeLock(id);
    },
    { immediate: true },
);
onUnmounted(() => {
    if (live && data.value) leaveWakeLock(data.value.workout_id);
});

const headerButton = 'flex min-h-tap min-w-tap items-center justify-center text-accent';
</script>

<template>
    <main class="bb-screen-content flex flex-col gap-3.5">
        <div class="flex items-center gap-1">
            <button type="button" :class="headerButton" aria-label="Close workout" aria-description="The workout stays open in the bar above the tabs" @click="router.back()">
                <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="m6 9 6 6 6-6" />
                </svg>
            </button>
            <span class="flex-1" />
            <ActionMenu v-if="lookBack.length" label="Look back" :items="lookBack" data-test="look-back" @select="openEarlier">
                <svg viewBox="0 0 24 24" class="size-6 text-accent" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <rect x="4" y="5" width="16" height="15" rx="2" />
                    <path d="M4 9h16M9 3v4M15 3v4" stroke-linecap="round" />
                </svg>
            </ActionMenu>
        </div>

        <ReadState v-if="!data" :failure="failure" what="This workout" @retry="load().catch(() => undefined)" />
        <template v-else>
            <h1 class="text-xl font-bold text-primary-text">{{ data.title }}</h1>
            <p v-if="data.deload_banner" class="rounded-xl p-2.5 text-sm" :class="toneClasses[data.deload_banner.tone]" data-test="deload">
                {{ data.deload_banner.text }}
            </p>
            <p v-if="error" class="rounded-xl p-2.5 text-sm" :class="toneClasses.warning" role="alert" data-test="error">{{ error }}</p>

            <WorkoutExerciseCard v-for="(exercise, index) in data.exercises" :key="exercise.id" :exercise="exercise" :index="index" :workout="data" @send="send" />
            <p v-if="!data.exercises.length" class="text-sm text-secondary-text" data-test="no-exercises">This workout has no exercises.</p>

            <footer class="sticky bottom-0 -mx-4 mt-auto flex flex-col gap-2 bg-surface px-4 py-2.5">
                <p v-if="data.feedback_banner" class="rounded-xl p-2.5 text-sm font-semibold" :class="toneClasses[data.feedback_banner.tone]" data-test="feedback-banner">
                    <PendingMark v-if="pending" derived>{{ data.feedback_banner.text }}</PendingMark>
                    <template v-else>{{ data.feedback_banner.text }}</template>
                </p>
                <p v-if="data.next_meal" class="text-sm text-secondary-text" data-test="next-meal">{{ data.next_meal }}</p>
                <template v-if="data.is_editable">
                    <button
                        type="button"
                        class="min-h-tap w-full rounded-xl bg-accent font-semibold text-on-accent disabled:opacity-40"
                        :disabled="!canFinish"
                        data-test="finish"
                        @click="finish"
                    >
                        Finish workout
                    </button>
                    <p v-if="pending" class="text-center" data-test="finish-pending"><PendingMark /></p>
                </template>
            </footer>

            <div v-if="confirm?.confirm" data-test="confirm">
                <BottomSheet
                    :open="true"
                    :title="confirm.confirm.title"
                    :save-title="confirm.confirm.action"
                    :error="error"
                    :heights="['half']"
                    @cancel="confirm = null"
                    @save="confirmed"
                >
                    <p class="p-4 text-primary-text">{{ confirm.confirm.message }}</p>
                </BottomSheet>
            </div>
        </template>
    </main>
</template>
