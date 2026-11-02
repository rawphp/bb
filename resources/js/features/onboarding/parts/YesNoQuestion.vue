<script setup lang="ts">
import { computed } from 'vue';
import ChoiceList, { type Choice } from '@/design/ChoiceList.vue';
import StepHeading from './StepHeading.vue';
import StepNote from './StepNote.vue';

// A question with a Yes and a No row (UI-006). Ported from rp-clone's OnboardingParts.
const props = withDefaults(defineProps<{ question: string; detail?: string | null; modelValue?: boolean | null; disabled?: boolean }>(), {
    detail: null,
    modelValue: null,
    disabled: false,
});

const emit = defineEmits<{ 'update:modelValue': [answer: boolean] }>();

// ChoiceList keys rows by text or number, so the two answers ride as 'yes' and 'no'.
const choices: Choice<'yes' | 'no'>[] = [
    { id: 'yes', title: 'Yes' },
    { id: 'no', title: 'No' },
];
const picked = computed(() => (props.modelValue === null ? null : props.modelValue ? 'yes' : 'no'));
</script>

<template>
    <div class="flex flex-col gap-2">
        <StepHeading :text="question" />
        <StepNote v-if="detail" :text="detail" />
        <ChoiceList
            :label="question"
            :choices="choices"
            :model-value="picked"
            :disabled="disabled"
            @update:model-value="(id) => emit('update:modelValue', id === 'yes')"
        />
    </div>
</template>
