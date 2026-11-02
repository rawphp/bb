<script setup lang="ts">
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import ChoiceList from '@/design/ChoiceList.vue';
import { picked, toChoices, type Answers } from './choices';
import OnboardingCallout from './parts/OnboardingCallout.vue';
import StepNote from './parts/StepNote.vue';
import YesNoQuestion from './parts/YesNoQuestion.vue';

type Goal = NonNullable<Answers['goal']>;

// Step 1: the goal (ONB-003) and the recent diet (ONB-021). The server says which goals
// are open and when to advise Maintenance first. Ported from rp-clone's OnboardingView.
defineProps<{ goal: OnboardingShowOutput['goal']; locked?: boolean }>();

const emit = defineEmits<{ answer: [answers: Answers] }>();
</script>

<template>
    <div class="flex flex-col gap-4">
        <StepNote :text="goal.help" />
        <ChoiceList
            label="Goal"
            :choices="toChoices<Goal>(goal.options)"
            :model-value="picked<Goal>(goal.options)"
            :disabled="locked"
            @update:model-value="(value) => emit('answer', { goal: value })"
        />
        <YesNoQuestion
            :question="goal.recent_diet.question"
            :detail="goal.recent_diet.detail"
            :model-value="goal.recent_diet.answer"
            :disabled="locked"
            @update:model-value="(answer) => emit('answer', { finished_diet_recently: answer })"
        />
        <div v-if="goal.advice" class="flex flex-col items-start gap-2">
            <OnboardingCallout :text="goal.advice.text" />
            <button
                type="button"
                data-test="advice"
                class="min-h-tap rounded-xl bg-accent/15 px-4 font-semibold text-accent active:opacity-70 disabled:opacity-50"
                :disabled="locked"
                @click="emit('answer', { goal: goal.advice.action_goal as Goal })"
            >
                {{ goal.advice.action_title }}
            </button>
        </div>
    </div>
</template>
