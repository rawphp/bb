<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { write } from '@/app/call';
import { writeFailureMessage } from '@/app/errors';
import { entries, isPending, pendingInput } from '@/app/outbox';
import ReadState from '@/app/ReadState.vue';
import { useScreenTop } from '@/app/screenTop';
import { useHoldUpdates } from '@/app/updates';
import { useRead } from '@/app/useRead';
import type { WorkoutFinishInput } from '@/contracts/workout_finish';
import type { WorkoutFeedbackAnswerInput } from '@/contracts/workout_feedback_answer';
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import type { WorkoutSkipInput } from '@/contracts/workout_skip';
import type { WorkoutStartInput } from '@/contracts/workout_start';
import ActionMenu, { type ActionMenuItem } from '@/design/ActionMenu.vue';
import BottomSheet from '@/design/BottomSheet.vue';
import PendingMark from '@/design/PendingMark.vue';
import { toneClasses } from '@/design/tones';
import { showWorkout } from './activeWorkout';
import { holdWakeLock, leaveWakeLock, releaseWakeLock } from './wakeLock';
import FeedbackSheet from './sheets/FeedbackSheet.vue';
import LookBackSheet from './sheets/LookBackSheet.vue';
import NoteSheet from './sheets/NoteSheet.vue';
import RelabelSheet from './sheets/RelabelSheet.vue';
import ReorderSheet from './sheets/ReorderSheet.vue';
import RIRInfoSheet from './sheets/RIRInfoSheet.vue';
import SetMenuSheet from './sheets/SetMenuSheet.vue';
import SwapSheet from './sheets/SwapSheet.vue';
import WorkoutHelpSheet from './sheets/WorkoutHelpSheet.vue';
import ExercisePickerSheet from './sheets/ExercisePickerSheet.vue';
import WorkoutExerciseCard from './WorkoutExerciseCard.vue';
import type { WorkoutSheet, WorkoutWrite } from './workoutWrite';

// The workout screen for the gym (WO-001 to WO-025): the session title, each exercise with
// its sets, the post-workout meal (WO-009) and Finish (WO-010). Sized for the gym (UI-020):
// every control is at least 48 px, weight and reps open the number pads, and nothing is a
// swipe. Every change goes through the outbox (OFF-006), so a whole session works offline;
// the first one starts the session (workout_start) when the server says it has not started.
// While a change waits, the server's numbers show as pending (OFF-008). While the session is
// under way the screen holds a wake lock (PWA-030) and keeps the bar above the tabs current
// (NAV-004). The header opens Look back (WO-008) and Help (WO-030) and the workout menu
// (WO-031); the set, exercise and feedback buttons open their sheets too. The screen owns
// which sheet is open and sends what a sheet asks through the outbox. A due feedback opens
// its sheet by itself (FB-001) until the user cancels it. The script holds which sheet and
// question is open and what failed to store (VIEW-002). Ported from rp-clone's WorkoutView.
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
        // A skip is for a session that never began: it does not start it first.
        if (workout.status === 'not_started' && !started.value && !waiting('workout_start') && change.name !== 'workout_skip') {
            const start: WorkoutStartInput = { workout_id: workout.workout_id };
            await write('workout_start', { ...start }, { label: `Started ${workout.day_label}` });
            started.value = true;
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
    if (!workout || !canFinish.value) return;
    finishing.value = true;
    const input: WorkoutFinishInput = { workout_id: workout.workout_id };
    void queue({ name: 'workout_finish', input: { ...input }, label: `Finished ${workout.day_label}`, haptic: true }).then((done) => {
        if (done) releaseWakeLock(workout.workout_id);
        else finishing.value = false;
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
// Replay drops a sent entry before the read that follows it arrives, so what this screen
// stored is kept until a new read says otherwise: Finish from the tap (a double tap sends
// one), and the start until the session reads as begun. A refusal is followed by no read
// (OFF-011), so a refused start or finish of this workout lets go of its mark.
const finishing = ref(false);
const started = ref(false);
watch(data, (now) => {
    finishing.value = false;
    if (now?.status !== 'not_started') started.value = false;
});
const refused = (name: string) =>
    entries.value.filter((entry) => entry.name === name && entry.refusal !== undefined && entry.input.workout_id === data.value?.workout_id).length;
watch(
    () => refused('workout_finish'),
    (now, before) => {
        if (now > before) finishing.value = false;
    },
);
watch(
    () => refused('workout_start'),
    (now, before) => {
        if (now > before) started.value = false;
    },
);
const finishWaiting = computed(() => finishing.value || waiting('workout_finish'));
const canFinish = computed(() => !finishWaiting.value && (data.value?.can_finish === true || pending.value));

// The one sheet that is open, and the feedback sheets the user cancelled this visit.
const sheet = ref<WorkoutSheet | null>(null);
const cancelled = ref<string[]>([]);

function closeSheet(): void {
    if (sheet.value?.name === 'feedback') cancelled.value.push(sheet.value.muscle);
    sheet.value = null;
}

// FB-001: the first feedback that is due and unanswered, with no answer waiting in the outbox.
const answerWaiting = (muscle: string) =>
    data.value !== undefined &&
    pendingInput<WorkoutFeedbackAnswerInput>('workout_feedback_answer', (input) => input.workout_id === data.value!.workout_id && input.muscle_group === muscle) !== undefined;
const dueFeedback = computed(() => data.value?.feedback.find((entry) => entry.is_due && !entry.is_answered)?.muscle_group);
const autoFeedback = computed(() => {
    const muscle = dueFeedback.value;
    return data.value?.is_editable && muscle !== undefined && !cancelled.value.includes(muscle) && !answerWaiting(muscle) ? muscle : undefined;
});
watch(
    autoFeedback,
    (muscle) => {
        if (muscle !== undefined && sheet.value === null) sheet.value = { name: 'feedback', muscle };
    },
    { immediate: true },
);

function openFeedback(): void {
    if (dueFeedback.value !== undefined) sheet.value = { name: 'feedback', muscle: dueFeedback.value };
}

// WO-031: the workout menu.
const dayMenu: ActionMenuItem[] = [
    { key: 'add', title: 'Add exercise' },
    { key: 'reorder', title: 'Reorder exercises' },
    { key: 'skip', title: 'Skip workout' },
    { key: 'rates', title: 'Progression rates' },
    { key: 'relabel', title: 'Relabel day' },
];

function chooseDay(key: string): void {
    const workout = data.value;
    if (!workout) return;
    if (key === 'add') sheet.value = { name: 'add-exercise' };
    else if (key === 'reorder') sheet.value = { name: 'reorder' };
    else if (key === 'relabel') sheet.value = { name: 'relabel' };
    else if (key === 'rates') void router.push(`/train/mesocycles/${workout.mesocycle_id}/rates`);
    else if (key === 'skip') {
        const input: WorkoutSkipInput = { workout_id: workout.workout_id };
        send({
            name: 'workout_skip',
            input: { ...input },
            label: `Skipped ${workout.day_label}`,
            confirm: { title: "Skip this workout? This can't be undone.", message: 'The session is marked skipped.', action: 'Skip workout' },
        });
    }
}

// PWA-030 and NAV-004: what the session's state means for the wake lock and the bar.
// A /dev/screens fixture is not the user's session: it leaves both alone.
const top = useScreenTop();
const live = props.payload === undefined;
const onShow = computed(() => top?.value ?? true);
// A session under way, counting a start that waits to sync, and one over, counting a finish
// that waits (OFF-021).
const over = computed(() => data.value?.status === 'finished' || data.value?.status === 'skipped' || finishWaiting.value);
const underWay = computed(() => !over.value && (data.value?.status === 'in_progress' || (data.value?.status === 'not_started' && (started.value || waiting('workout_start')))));
// PWA-035: no update reloads the app mid-workout.
useHoldUpdates(() => live && underWay.value);
watch(
    [() => data.value?.active_bar_text, onShow, underWay, over],
    () => {
        const workout = data.value;
        if (!workout || !live) return;
        const id = workout.workout_id;
        showWorkout(id, path.value, workout.active_bar_text);
        if (over.value) releaseWakeLock(id);
        else if (underWay.value && onShow.value) holdWakeLock(id);
        else if (underWay.value) leaveWakeLock(id);
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
            <button type="button" :class="headerButton" aria-label="Look back" aria-haspopup="dialog" data-test="look-back" @click="sheet = { name: 'look-back' }">
                <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <rect x="4" y="5" width="16" height="15" rx="2" />
                    <path d="M4 9h16M9 3v4M15 3v4" stroke-linecap="round" />
                </svg>
            </button>
            <button type="button" :class="headerButton" aria-label="Help" aria-haspopup="dialog" data-test="help" @click="sheet = { name: 'help' }">
                <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <circle cx="12" cy="12" r="9.25" />
                    <path d="M9.5 9.25a2.5 2.5 0 1 1 3.5 2.3c-.6.27-1 .87-1 1.53v.67" stroke-linecap="round" />
                    <circle cx="12" cy="16.75" r=".6" fill="currentColor" stroke="none" />
                </svg>
            </button>
            <ActionMenu v-if="data?.is_editable" label="Workout menu" :items="dayMenu" data-test="day-menu" @select="chooseDay">
                <svg viewBox="0 0 24 24" class="size-6 text-accent" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <circle cx="12" cy="12" r="9.25" />
                    <path d="M8 12h.01M12 12h.01M16 12h.01" stroke-linecap="round" stroke-width="2.5" />
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

            <WorkoutExerciseCard v-for="(exercise, index) in data.exercises" :key="exercise.id" :exercise="exercise" :index="index" :workout="data" @send="send" @open="(next) => (sheet = next)" />
            <p v-if="!data.exercises.length" class="text-sm text-secondary-text" data-test="no-exercises">This workout has no exercises.</p>

            <footer class="sticky bottom-0 -mx-4 mt-auto flex flex-col gap-2 bg-surface px-4 py-2.5">
                <!-- FB-001: a tap opens the feedback that is due. -->
                <button
                    v-if="data.feedback_banner"
                    type="button"
                    class="min-h-tap w-full rounded-xl p-2.5 text-left text-sm font-semibold"
                    :class="toneClasses[data.feedback_banner.tone]"
                    data-test="feedback-banner"
                    @click="openFeedback"
                >
                    <PendingMark v-if="pending" derived>{{ data.feedback_banner.text }}</PendingMark>
                    <template v-else>{{ data.feedback_banner.text }}</template>
                </button>
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

            <SetMenuSheet v-if="sheet?.name === 'set-menu'" :payload="data" :set-id="sheet.setId" @send="send" @close="closeSheet" />
            <RIRInfoSheet v-else-if="sheet?.name === 'rir'" :payload="data" @close="closeSheet" />
            <SwapSheet v-else-if="sheet?.name === 'swap'" :payload="data" :exercise-id="sheet.exerciseId" @send="send" @close="closeSheet" />
            <NoteSheet v-else-if="sheet?.name === 'note'" :payload="data" :exercise-id="sheet.exerciseId" :pinned="sheet.pinned" @send="send" @close="closeSheet" />
            <FeedbackSheet v-else-if="sheet?.name === 'feedback'" :payload="data" :muscle="sheet.muscle" @send="send" @close="closeSheet" />
            <LookBackSheet v-else-if="sheet?.name === 'look-back'" :payload="data" @close="closeSheet" />
            <WorkoutHelpSheet v-else-if="sheet?.name === 'help'" :payload="data" @close="closeSheet" />
            <ExercisePickerSheet v-else-if="sheet?.name === 'add-exercise'" :payload="data" @send="send" @close="closeSheet" />
            <ReorderSheet v-else-if="sheet?.name === 'reorder'" :payload="data" @send="send" @close="closeSheet" />
            <RelabelSheet v-else-if="sheet?.name === 'relabel'" :payload="data" @send="send" @close="closeSheet" />

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
