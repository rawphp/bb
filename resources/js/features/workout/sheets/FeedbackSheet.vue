<script setup lang="ts">
import { computed, ref } from 'vue';
import { pendingInput } from '@/app/outbox';
import type { WorkoutFeedbackAnswerInput } from '@/contracts/workout_feedback_answer';
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import BottomSheet from '@/design/BottomSheet.vue';
import ChoiceList from '@/design/ChoiceList.vue';
import HelpButton from '@/design/HelpButton.vue';
import { toneClasses } from '@/design/tones';
import type { WorkoutWrite } from '../workoutWrite';

type Question = WorkoutShowOutput['feedback'][number]['questions'][number];

// The Feedback sheet of one muscle (FB-001 to FB-009): joint pain per exercise, then
// soreness, pump and volume, each with a ? icon (FB-006). A tap outside or Escape never
// closes it, so answers aren't lost (UI-022); only Cancel does. The server sends the
// questions and their answers; a waiting answer in the outbox seeds a reopened sheet
// (OFF-009). The script holds the picked answers (VIEW-002). Ported from rp-clone's FeedbackSheet.
const props = defineProps<{ payload?: WorkoutShowOutput; muscle: string }>();

const emit = defineEmits<{ send: [write: WorkoutWrite]; close: [] }>();

const entry = computed(() => props.payload?.feedback.find((found) => found.muscle_group === props.muscle));
const questions = computed(() => entry.value?.questions ?? []);
const readOnly = computed(() => !props.payload?.is_editable || entry.value?.can_edit === false);

const waiting = props.payload
    ? pendingInput<WorkoutFeedbackAnswerInput>('workout_feedback_answer', (input) => input.workout_id === props.payload!.workout_id && input.muscle_group === props.muscle)
    : undefined;

// An answer waiting in the outbox is newer than the server's.
function seed(question: Question): string | null {
    const waited = !waiting
        ? undefined
        : question.kind === 'joint'
          ? waiting.joint_pain.find((pain) => pain.exercise_id === question.exercise_id)?.answer
          : waiting[question.kind];
    return waited ?? question.answer ?? null;
}

// One answer per question, in the order the server sent them.
const answers = ref<(string | null)[]>(questions.value.map(seed));
const complete = computed(() => questions.value.length > 0 && answers.value.every((answer) => answer !== null));

// A chosen answer that carries a note (a safety note on joint pain) shows below the joint questions.
const notes = computed(() => {
    const found = questions.value.flatMap((question, index) =>
        question.kind === 'joint' ? [question.choices.find((choice) => choice.value === answers.value[index])?.note] : [],
    );
    return [...new Set(found.filter((note): note is string => !!note))];
});
const lastJoint = computed(() => questions.value.map((question) => question.kind).lastIndexOf('joint'));

function answerOf(kind: Question['kind']): string | undefined {
    const index = questions.value.findIndex((question) => question.kind === kind);
    return index === -1 ? undefined : (answers.value[index] ?? undefined);
}

function save(): void {
    const current = entry.value;
    if (!props.payload || !current || !complete.value || readOnly.value) return;
    const soreness = answerOf('soreness');
    const input = {
        workout_id: props.payload.workout_id,
        muscle_group: current.muscle_group,
        joint_pain: questions.value.flatMap((question, index) =>
            question.kind === 'joint' && question.exercise_id ? [{ exercise_id: question.exercise_id, answer: answers.value[index] }] : [],
        ),
        pump: answerOf('pump'),
        volume: answerOf('volume'),
        ...(soreness === undefined ? {} : { soreness }),
    } as WorkoutFeedbackAnswerInput;
    emit('send', { name: 'workout_feedback_answer', input: { ...input }, label: `Answered ${current.title} feedback` });
    emit('close');
}
</script>

<template>
    <BottomSheet
        :open="true"
        :title="entry?.title ?? 'Feedback'"
        :save-title="readOnly ? null : 'Save'"
        :cancel-title="readOnly ? 'Done' : 'Cancel'"
        :save-disabled="!complete"
        :dismissible="false"
        @cancel="emit('close')"
        @save="save"
    >
        <div class="flex flex-col gap-5 p-4">
            <template v-for="(question, index) in questions" :key="index">
                <section class="flex flex-col gap-2">
                    <div class="flex items-start gap-1">
                        <h3 class="flex-1 pt-3 text-lg font-semibold text-primary-text">{{ question.text }}</h3>
                        <HelpButton :title="question.help.title" :message="question.help.body" :to="question.help.article ? `/learn/${question.help.article}` : undefined" />
                    </div>
                    <ChoiceList
                        v-model="answers[index]"
                        :label="question.text"
                        :choices="question.choices.map((choice) => ({ id: choice.value, title: choice.label }))"
                        :disabled="readOnly"
                    />
                </section>
                <template v-if="index === lastJoint">
                    <p v-for="note in notes" :key="note" class="rounded-xl p-3 text-sm font-semibold" :class="toneClasses.warning" role="note" data-test="safety-note">{{ note }}</p>
                </template>
            </template>
        </div>
    </BottomSheet>
</template>
