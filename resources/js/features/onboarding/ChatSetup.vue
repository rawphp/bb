<script setup lang="ts">
import { ref, watch } from 'vue';
import { readFresh, writeNow } from '@/app/call';
import { writeFailureMessage } from '@/app/errors';
import { offerInstall } from '@/app/install';
import type { OnboardingAnswerInput } from '@/contracts/onboarding_answer';
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import BottomSheet from '@/design/BottomSheet.vue';
import type { Answers } from './choices';
import ReviewStep from './ReviewStep.vue';
import ScreeningStep from './ScreeningStep.vue';

// Setup inside the Coach chat (AGT-031, AGT-032), for the two steps the Coach does not do
// in conversation. The health check opens as a sheet when setup reaches it: the user's
// ticks and its Done are sent from here, never by the model (SAFE-001). Done then reads
// setup again and tells the Coach the step it is now on, in the server's title, since the
// answers may have sent it back to the goal (ONB-005); while setup is still on the check,
// the sheet stays open and the Coach is told nothing. The review shows as a card whose Start my plan sends the confirm line
// for onboarding_complete as the whole message (AGT-010). `busy`: the Coach is taking a
// message, so nothing here sends one. `changed` asks the chat to read setup again.
const props = defineProps<{ setup: OnboardingShowOutput; busy: boolean }>();
const emit = defineEmits<{ say: [text: string]; changed: [] }>();

const CONFIRM_START = 'confirm: onboarding_complete{}';
const SCREENED = "I've answered the health check.";

const open = ref(false);
const saving = ref(false);
const error = ref<string | null>(null);

watch(
    () => props.setup.step.key,
    (key) => {
        open.value = key === 'screening';
        error.value = null;
    },
    { immediate: true },
);

async function post(input: OnboardingAnswerInput): Promise<boolean> {
    saving.value = true;
    error.value = null;
    try {
        await writeNow('onboarding_answer', { ...input });
        return true;
    } catch (failed) {
        error.value = writeFailureMessage(failed, 'Your answer could not be saved.');
        return false;
    } finally {
        saving.value = false;
        emit('changed');
    }
}

async function answer(answers: Answers): Promise<void> {
    if (!saving.value) await post({ answers, move: 'stay' });
}

async function done(): Promise<void> {
    if (saving.value || props.busy) return;
    if (!(await post({ answers: {}, move: 'next' }))) return;
    let now: OnboardingShowOutput['step'] | null = null;
    try {
        now = (await readFresh<OnboardingShowOutput>('onboarding_show', {})).step;
    } catch {
        // Setup could not be read: the Coach reads it itself before it goes on.
    }
    if (now?.key === 'screening') return;
    open.value = false;
    emit('say', now === null ? SCREENED : `${SCREENED} Setup is now at: ${now.title}`);
}

// The install is offered on this tap, as the stepper's Start my plan does (PWA-003).
function start(): void {
    if (props.busy) return;
    void offerInstall();
    emit('say', CONFIRM_START);
}
</script>

<template>
    <li v-if="setup.step.key === 'screening'" class="flex flex-col gap-2 self-stretch rounded-xl border border-secondary-text/25 bg-background p-3">
        <p class="text-sm text-primary-text">You answer the health check yourself, on its own sheet.</p>
        <button type="button" data-test="open-screening" class="min-h-tap self-start text-sm font-semibold text-accent" @click="open = true">
            Answer the health check
        </button>
        <BottomSheet :open="open" :title="setup.step.title" save-title="Done" :save-disabled="saving || busy" cancel-title="Close" :error="error" @cancel="open = false" @save="done">
            <div class="p-4">
                <ScreeningStep :screening="setup.screening" :locked="saving" @answer="answer" />
            </div>
        </BottomSheet>
    </li>
    <li v-else-if="setup.step.key === 'review'" data-test="review-card" class="flex flex-col gap-3 self-stretch rounded-xl border border-secondary-text/25 bg-surface p-3">
        <h3 class="text-base font-semibold text-primary-text">{{ setup.step.title }}</h3>
        <ReviewStep :review="setup.review" :editable="false" />
        <button
            type="button"
            data-test="start-plan"
            :disabled="busy"
            class="min-h-tap w-full rounded-xl bg-accent font-semibold text-on-accent active:opacity-70 disabled:opacity-40"
            @click="start"
        >
            {{ setup.step.next_title }}
        </button>
    </li>
</template>
