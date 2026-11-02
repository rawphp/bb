<script setup lang="ts">
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import ChoiceList from '@/design/ChoiceList.vue';
import { picked, toChoices, type Answers } from './choices';

type Level = NonNullable<Answers['experience']>;

// Step 7: training experience (ONB-010). The server's rows say how long each level's
// mesocycles start (TBL-020). Ported from rp-clone's TrainingSteps.
defineProps<{ experience: OnboardingShowOutput['experience']; locked?: boolean }>();

const emit = defineEmits<{ answer: [answers: Answers] }>();
</script>

<template>
    <ChoiceList
        label="Training experience"
        :choices="toChoices<Level>(experience.options)"
        :model-value="picked<Level>(experience.options)"
        :disabled="locked"
        @update:model-value="(value) => emit('answer', { experience: value })"
    />
</template>
