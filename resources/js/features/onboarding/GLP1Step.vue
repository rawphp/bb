<script setup lang="ts">
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import type { Answers } from './choices';
import YesNoQuestion from './parts/YesNoQuestion.vue';

// Step 4: the weight-loss drug question (ONB-006, SAFE-013). Ported from rp-clone's OnboardingView.
defineProps<{ glp1: OnboardingShowOutput['glp1']; disabled?: boolean }>();

const emit = defineEmits<{ answer: [answers: Answers] }>();
</script>

<template>
    <YesNoQuestion
        :question="glp1.question"
        :detail="glp1.detail"
        :model-value="glp1.answer"
        :disabled="disabled"
        @update:model-value="(answer) => emit('answer', { uses_glp1: answer })"
    />
</template>
