<script setup lang="ts">
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import ChoiceList from '@/design/ChoiceList.vue';
import { picked, toChoices, type Answers } from './choices';
import StepNote from './parts/StepNote.vue';

// Step 9: the first mesocycle (ONB-012): the templates that fit, the recommended one
// tagged, then the Meso Builder and "I'll build it later". The server filters and
// preselects. Ported from rp-clone's TrainingSteps.
defineProps<{ trainingPlan: OnboardingShowOutput['training_plan']; locked?: boolean }>();

const emit = defineEmits<{ answer: [answers: Answers] }>();
</script>

<template>
    <div class="flex flex-col gap-4">
        <StepNote v-if="trainingPlan.note" :text="trainingPlan.note" />
        <ChoiceList
            label="Training plan"
            :choices="toChoices<string>(trainingPlan.options)"
            :model-value="picked<string>(trainingPlan.options)"
            :disabled="locked"
            @update:model-value="(value) => emit('answer', { training_plan: value })"
        />
    </div>
</template>
