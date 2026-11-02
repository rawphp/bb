<script setup lang="ts">
import { computed, ref } from 'vue';
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import { useWrite, type WriteOutcome } from '@/app/useWrite';
import type { OnboardingAnswerInput } from '@/contracts/onboarding_answer';
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import StepFlow, { type StepFlowStep } from '@/design/StepFlow.vue';
import { toneClasses } from '@/design/tones';
import AboutYouStep from './AboutYouStep.vue';
import BodyFatStep from './BodyFatStep.vue';
import type { Answers, Move } from './choices';
import GLP1Step from './GLP1Step.vue';
import GoalStep from './GoalStep.vue';
import OnboardingCallout from './parts/OnboardingCallout.vue';
import ScreeningStep from './ScreeningStep.vue';

// First-run setup (ONB-002): one StepFlow per step, the step the server says the user is
// on (UI-007). Every answer is saved with onboarding_answer and the step read again, so
// the server decides what shows, what is open and when Next works (CAP-001). Ported from
// rp-clone's OnboardingView. The steps after body fat and the review come with #65.
const props = defineProps<{ payload?: OnboardingShowOutput }>();

const { data, failure, load } = useRead<OnboardingShowOutput>('onboarding_show', {}, 'Your setup could not be loaded.', props.payload);
const { send, busy, error } = useWrite(load);

// What is typed (a name, a number, a date) waits here and goes with the next write, so a
// keystroke is not a write and a background refresh does not overwrite it.
const drafts = ref<Answers>({});

function draft(answers: Answers): void {
    drafts.value = { ...drafts.value, ...answers };
}

async function post(answers: Answers, move: Move): Promise<WriteOutcome | null> {
    const outcome = await send('onboarding_answer', { answers, move } satisfies OnboardingAnswerInput, 'Your answer could not be saved.');
    if (outcome === 'done') {
        // Drop what was saved; anything typed while the write was out stays.
        const left = { ...drafts.value };
        for (const key of Object.keys(answers) as (keyof Answers)[]) if (left[key] === answers[key]) delete left[key];
        drafts.value = left;
    }
    return outcome;
}

async function save(answers: Answers, move: Move = 'stay'): Promise<void> {
    const held = drafts.value;
    // A unit switch names the answer a number was typed into: save the number in its own
    // unit first, so the server converts it rather than the switch dropping it.
    if (Object.keys(answers).some((key) => key in held)) {
        if ((await post(held, 'stay')) === 'done') await post(answers, move);
        return;
    }
    await post({ ...held, ...answers }, move);
}

const step = computed((): StepFlowStep | null => {
    const shown = data.value?.step;
    if (!shown) return null;
    if (busy.value) return { ...shown, can_go_back: false, can_continue: false, continue_hint: null };
    // Typed answers the server has not seen are its to judge: Next sends them, and a step
    // that cannot continue yet comes back with the server's reason.
    if (Object.keys(drafts.value).length > 0) return { ...shown, can_continue: true, continue_hint: null };
    return shown;
});
</script>

<template>
    <main class="mx-auto w-full max-w-[560px] px-4 pb-6 pt-[max(1rem,env(safe-area-inset-top))]">
        <StepFlow v-if="data && step" :key="data.step.number" :step="step" @back="save({}, 'back')" @next="save({}, 'next')">
            <div class="flex flex-col gap-4">
                <OnboardingCallout v-for="notice in data.notices" :key="notice.text" :text="notice.text" :tone="notice.tone" />
                <GoalStep v-if="data.step.key === 'goal'" :goal="data.goal" :disabled="busy" @answer="save" />
                <AboutYouStep
                    v-else-if="data.step.key === 'about_you'"
                    :about="data.about_you"
                    :drafts="drafts"
                    :disabled="busy"
                    @answer="save"
                    @draft="draft"
                />
                <ScreeningStep v-else-if="data.step.key === 'screening'" :screening="data.screening" :disabled="busy" @answer="save" />
                <GLP1Step v-else-if="data.step.key === 'glp1'" :glp1="data.glp1" :disabled="busy" @answer="save" />
                <BodyFatStep v-else-if="data.step.key === 'body_fat'" :body-fat="data.body_fat" :disabled="busy" @answer="save" />
                <p v-if="error" role="alert" class="rounded-xl p-3 text-sm" :class="toneClasses.warning">{{ error }}</p>
            </div>
        </StepFlow>
        <ReadState v-else :failure="failure" what="Your setup" @retry="load" />
    </main>
</template>
