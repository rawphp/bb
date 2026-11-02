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

// One save at a time. A save is built from what the screen shows, so while one is out the
// step is read-only (`locked`): its picks are off and its typed fields take no typing. A
// Back or Next pressed meanwhile goes after the save, first press wins, unless the save is
// refused or gets no answer. A failed save remounts the step's fields, so they show what
// is saved, not what was refused; nothing can have been typed while it was out. While a
// move is out the whole step is off (the fieldset).
let wantsMove: 'next' | 'back' | null = null;
const saving = ref(false);
const moving = ref(false);
const locked = computed(() => saving.value || moving.value);
const fields = ref(0);

async function post(input: OnboardingAnswerInput): Promise<boolean> {
    const done = (await send('onboarding_answer', { ...input }, 'Your answer could not be saved.')) === 'done';
    if (!done) fields.value += 1;
    return done;
}

async function go(to: 'next' | 'back'): Promise<void> {
    moving.value = true;
    try {
        await post({ answers: {}, move: to });
    } finally {
        moving.value = false;
    }
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
    if (moving.value) return;
    if (saving.value) wantsMove ??= to;
    else void go(to);
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
            <fieldset :key="fields" :disabled="moving" class="flex min-w-0 flex-col gap-4">
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
