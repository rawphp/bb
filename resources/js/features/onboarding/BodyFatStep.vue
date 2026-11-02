<script setup lang="ts">
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import BucketCard from './BucketCard.vue';
import type { Answers } from './choices';
import StepNote from './parts/StepNote.vue';

type Bucket = NonNullable<Answers['body_fat']>;

// Step 5: body fat (ONB-007). Four buckets for the user's sex, labelled by the server
// (TBL-001). Ported from rp-clone's BodyFatStep.
defineProps<{ bodyFat: OnboardingShowOutput['body_fat']; disabled?: boolean }>();

const emit = defineEmits<{ answer: [answers: Answers] }>();
</script>

<template>
    <div class="flex flex-col gap-4">
        <StepNote :text="bodyFat.help" />
        <div class="grid grid-cols-2 gap-3">
            <BucketCard
                v-for="(option, index) in bodyFat.options"
                :key="option.value"
                :title="option.title"
                :sex="bodyFat.sex ?? 'female'"
                :index="index"
                :selected="option.is_selected"
                :disabled="disabled || !option.is_enabled"
                @select="emit('answer', { body_fat: option.value as Bucket })"
            />
        </div>
    </div>
</template>
