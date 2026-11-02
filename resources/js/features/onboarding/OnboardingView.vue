<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { offerInstall } from '@/app/install';
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import { useWrite } from '@/app/useWrite';
import type { OnboardingAnswerInput } from '@/contracts/onboarding_answer';
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import StepFlow, { type StepFlowStep } from '@/design/StepFlow.vue';
import { toneClasses } from '@/design/tones';
import AboutYouStep from './AboutYouStep.vue';
import BodyFatStep from './BodyFatStep.vue';
import CaloriesStep from './CaloriesStep.vue';
import type { Answers } from './choices';
import DailyStepsStep from './DailyStepsStep.vue';
import ExperienceStep from './ExperienceStep.vue';
import GLP1Step from './GLP1Step.vue';
import GoalStep from './GoalStep.vue';
import MealsStep from './MealsStep.vue';
import OnboardingCallout from './parts/OnboardingCallout.vue';
import RateLengthStep from './RateLengthStep.vue';
import ReviewStep from './ReviewStep.vue';
import ScreeningStep from './ScreeningStep.vue';
import StartingPlanView from './StartingPlanView.vue';
import TrainingDaysStep from './TrainingDaysStep.vue';
import TrainingPlanStep from './TrainingPlanStep.vue';
import WeighInsStep from './WeighInsStep.vue';

// First-run setup (ONB-002): one StepFlow per step, the step the server says the user is
// on (UI-007). Every answer is saved with onboarding_answer as it is made (a pick at once,
// a typed field when it loses focus) and the step read again, so the server decides what
// shows, what is open and when Next works (CAP-001). On the review, Next is "Start my
// plan": onboarding_complete builds the plan behind StartingPlanView, then Today opens,
// which decides whether its tour runs (ONB-020, ONB-030). `pending` marks the server's
// numbers while a change waits to sync (OFF-008). Ported from rp-clone's OnboardingView.
const props = withDefaults(defineProps<{ payload?: OnboardingShowOutput; pending?: boolean }>(), { pending: false });

const router = useRouter();
const { data, failure, load } = useRead<OnboardingShowOutput>('onboarding_show', {}, 'Your setup could not be loaded.', props.payload);
const { send, error: answerError } = useWrite(load);
// The plan is built once; after it the screen leaves, so nothing is read again.
const { send: sendStart, error: startError } = useWrite(async () => undefined);
const error = computed(() => startError.value ?? answerError.value);

// One save at a time. A save is built from what the screen shows, so while one is out the
// step is read-only (`locked`): its picks are off and its typed fields take no typing. A
// Back or Next pressed meanwhile goes after the save, first press wins, unless the save is
// refused or gets no answer. A failed save remounts the step's fields, so they show what
// is saved, not what was refused; nothing can have been typed while it was out. While a
// move is out the whole step is off (the fieldset).
let wantsMove: 'next' | 'back' | null = null;
const saving = ref(false);
const moving = ref(false);
const starting = ref(false);
const locked = computed(() => saving.value || moving.value || starting.value);
const fields = ref(0);

async function post(input: OnboardingAnswerInput): Promise<boolean> {
    startError.value = null;
    const done = (await send('onboarding_answer', { ...input }, 'Your answer could not be saved.')) === 'done';
    if (!done) fields.value += 1;
    return done;
}

async function go(to: 'next' | 'back' | 'edit', editStep?: number): Promise<void> {
    moving.value = true;
    try {
        await post(to === 'edit' ? { answers: {}, move: to, edit_step: editStep } : { answers: {}, move: to });
    } finally {
        moving.value = false;
    }
}

// "Start my plan". The install is offered on this tap, as Chrome shows its prompt only
// on a user gesture (PWA-003). A start that gets no answer may have worked; pressed again
// it is the same write with the same key, so the server replays it.
async function start(): Promise<void> {
    if (locked.value) return;
    void offerInstall();
    starting.value = true;
    if ((await sendStart('onboarding_complete', {}, 'Your plan could not be started.')) === 'done') {
        await router.replace('/');
        return;
    }
    starting.value = false;
}

async function answer(answers: Answers): Promise<void> {
    if (locked.value) return;
    saving.value = true;
    let saved = false;
    try {
        saved = await post({ answers, move: 'stay' });
    } finally {
        saving.value = false;
    }
    const to = wantsMove;
    wantsMove = null;
    if (saved && to) await go(to);
}

function move(to: 'next' | 'back'): void {
    if (moving.value || starting.value) return;
    if (to === 'next' && data.value?.step.key === 'review') {
        void start();
        return;
    }
    if (saving.value) wantsMove ??= to;
    else void go(to);
}

const step = computed((): StepFlowStep | null => {
    const shown = data.value?.step;
    if (!shown) return null;
    return moving.value ? { ...shown, can_go_back: false, can_continue: false, continue_hint: null } : shown;
});

function edit(step: number): void {
    if (!locked.value) void go('edit', step);
}
</script>

<template>
    <main class="mx-auto w-full max-w-[560px] px-4 pb-6 pt-[max(1rem,env(safe-area-inset-top))]">
        <StartingPlanView v-if="data && starting" :title="data.review.starting_title" :text="data.review.starting_text" />
        <StepFlow v-else-if="data && step" :key="data.step.number" :step="step" :pending="pending" @back="move('back')" @next="move('next')">
            <fieldset :key="fields" :disabled="moving" class="flex min-w-0 flex-col gap-4">
                <OnboardingCallout v-for="notice in data.notices" :key="notice.text" :text="notice.text" :tone="notice.tone" />
                <GoalStep v-if="data.step.key === 'goal'" :goal="data.goal" :locked="locked" @answer="answer" />
                <AboutYouStep v-else-if="data.step.key === 'about_you'" :about="data.about_you" :locked="locked" @answer="answer" />
                <ScreeningStep v-else-if="data.step.key === 'screening'" :screening="data.screening" :locked="locked" @answer="answer" />
                <GLP1Step v-else-if="data.step.key === 'glp1'" :glp1="data.glp1" :locked="locked" @answer="answer" />
                <BodyFatStep v-else-if="data.step.key === 'body_fat'" :body-fat="data.body_fat" :locked="locked" @answer="answer" />
                <RateLengthStep v-else-if="data.step.key === 'rate_length'" :rate-length="data.rate_length" :locked="locked" :pending="pending" @answer="answer" />
                <ExperienceStep v-else-if="data.step.key === 'experience'" :experience="data.experience" :locked="locked" @answer="answer" />
                <TrainingDaysStep v-else-if="data.step.key === 'training_days'" :training-days="data.training_days" :locked="locked" @answer="answer" />
                <TrainingPlanStep v-else-if="data.step.key === 'training_plan'" :training-plan="data.training_plan" :locked="locked" @answer="answer" />
                <DailyStepsStep v-else-if="data.step.key === 'steps'" :steps="data.steps" :locked="locked" @answer="answer" />
                <MealsStep v-else-if="data.step.key === 'meals'" :meals="data.meals" :weekdays="data.training_days.days" :locked="locked" @answer="answer" />
                <WeighInsStep v-else-if="data.step.key === 'weigh_ins'" :weigh-ins="data.weigh_ins" :locked="locked" @answer="answer" />
                <CaloriesStep v-else-if="data.step.key === 'calories'" :calories="data.calories" :locked="locked" :pending="pending" @answer="answer" />
                <ReviewStep v-else-if="data.step.key === 'review'" :review="data.review" :locked="locked" :pending="pending" @edit="edit" />
                <p v-if="error" role="alert" class="rounded-xl p-3 text-sm" :class="toneClasses.warning">{{ error }}</p>
            </fieldset>
        </StepFlow>
        <ReadState v-else :failure="failure" what="Your setup" @retry="load" />
    </main>
</template>
