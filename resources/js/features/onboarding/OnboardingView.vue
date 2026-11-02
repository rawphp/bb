<script setup lang="ts">
import { computed, ref } from 'vue';
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import { useWrite } from '@/app/useWrite';
import type { OnboardingAnswerInput } from '@/contracts/onboarding_answer';
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import StepFlow, { type StepFlowStep } from '@/design/StepFlow.vue';
import { toneClasses } from '@/design/tones';
import AboutYouStep from './AboutYouStep.vue';
import BodyFatStep from './BodyFatStep.vue';
import type { Answers } from './choices';
import GLP1Step from './GLP1Step.vue';
import GoalStep from './GoalStep.vue';
import OnboardingCallout from './parts/OnboardingCallout.vue';
import ScreeningStep from './ScreeningStep.vue';

// First-run setup (ONB-002): one StepFlow per step, the step the server says the user is
// on (UI-007). Every answer is saved with onboarding_answer as it is made (a pick at once,
// a typed field when it loses focus) and the step read again, so the server decides what
// shows, what is open and when Next works (CAP-001). Ported from rp-clone's OnboardingView.
// The steps after body fat and the review come with #65.
const props = defineProps<{ payload?: OnboardingShowOutput }>();

const { data, failure, load } = useRead<OnboardingShowOutput>('onboarding_show', {}, 'Your setup could not be loaded.', props.payload);
const { send, error } = useWrite(load);

// One queue, in the order things were done. A pick reads what the screen shows, which is
// out of date while a save is out, so the step's picks are locked until it lands (`locked`).
// Typing is never locked: a typed answer made meanwhile waits its turn, and a Back or Next
// pressed meanwhile goes after the answers, first press wins. One that is refused, or gets
// no answer, drops what waits behind it: those were made on a screen now out of date.
// While a move is out the whole step is locked (the fieldset), so nothing is made on a
// screen that is going away.
const waiting: Answers[] = [];
let wantsMove: 'next' | 'back' | null = null;
const saving = ref(false);
const moving = ref(false);
const locked = computed(() => saving.value || moving.value);

// After a failed write the step's fields are remounted, so they show what is saved, not
// what was refused. Not under someone's typing: then it waits until focus leaves the step.
const fields = ref(0);
const fieldset = ref<HTMLFieldSetElement>();
let staleFields = false;

function resetFields(): void {
    staleFields = false;
    fields.value += 1;
}

function leftField(event: FocusEvent): void {
    if (staleFields && !fieldset.value?.contains(event.relatedTarget as Node | null)) resetFields();
}

async function post(input: OnboardingAnswerInput): Promise<boolean> {
    const done = (await send('onboarding_answer', { ...input }, 'Your answer could not be saved.')) === 'done';
    if (!done) {
        if (fieldset.value?.contains(document.activeElement)) staleFields = true;
        else resetFields();
    }
    return done;
}

async function drain(): Promise<void> {
    if (saving.value || moving.value) return;
    saving.value = true;
    try {
        for (let next = waiting.shift(); next; next = waiting.shift()) {
            if (!(await post({ answers: next, move: 'stay' }))) {
                waiting.length = 0;
                wantsMove = null;
            }
        }
    } finally {
        saving.value = false;
    }
    const to = wantsMove;
    wantsMove = null;
    if (!to) return;
    moving.value = true;
    try {
        await post({ answers: {}, move: to });
    } finally {
        moving.value = false;
    }
}

function answer(answers: Answers): void {
    if (moving.value) return;
    waiting.push(answers);
    void drain();
}

function move(to: 'next' | 'back'): void {
    if (moving.value) return;
    wantsMove ??= to;
    void drain();
}

const step = computed((): StepFlowStep | null => {
    const shown = data.value?.step;
    if (!shown) return null;
    return moving.value ? { ...shown, can_go_back: false, can_continue: false, continue_hint: null } : shown;
});
</script>

<template>
    <main class="mx-auto w-full max-w-[560px] px-4 pb-6 pt-[max(1rem,env(safe-area-inset-top))]">
        <StepFlow v-if="data && step" :key="data.step.number" :step="step" @back="move('back')" @next="move('next')">
            <fieldset ref="fieldset" :key="fields" :disabled="moving" class="flex min-w-0 flex-col gap-4" @focusout="leftField">
                <OnboardingCallout v-for="notice in data.notices" :key="notice.text" :text="notice.text" :tone="notice.tone" />
                <GoalStep v-if="data.step.key === 'goal'" :goal="data.goal" :locked="locked" @answer="answer" />
                <AboutYouStep v-else-if="data.step.key === 'about_you'" :about="data.about_you" :locked="locked" @answer="answer" />
                <ScreeningStep v-else-if="data.step.key === 'screening'" :screening="data.screening" :locked="locked" @answer="answer" />
                <GLP1Step v-else-if="data.step.key === 'glp1'" :glp1="data.glp1" :locked="locked" @answer="answer" />
                <BodyFatStep v-else-if="data.step.key === 'body_fat'" :body-fat="data.body_fat" :locked="locked" @answer="answer" />
                <p v-if="error" role="alert" class="rounded-xl p-3 text-sm" :class="toneClasses.warning">{{ error }}</p>
            </fieldset>
        </StepFlow>
        <ReadState v-else :failure="failure" what="Your setup" @retry="load" />
    </main>
</template>
