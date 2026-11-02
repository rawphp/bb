<script setup lang="ts">
import StepFlow from '../StepFlow.vue';

// The step flow in each VIEW-004 state.
const step = { counter: 'Step 2 of 4', title: 'About you', nextTitle: 'Next', canGoBack: true } as const;

const states = [
    { name: 'default', props: step, content: true },
    {
        name: 'last step',
        props: { counter: 'Step 4 of 4', title: 'Review', nextTitle: 'Done', canGoBack: true },
        content: true,
    },
    { name: 'empty', props: { ...step, counter: 'Step 1 of 4', canGoBack: false, canContinue: false }, content: false },
    { name: 'loading', props: { ...step, state: 'loading' }, content: true },
    { name: 'error', props: { ...step, state: 'error' }, content: true },
    { name: 'pending', props: { ...step, state: 'pending' }, content: true },
    { name: 'disabled', props: { ...step, disabled: true }, content: true },
] as const;
</script>

<template>
    <div class="flex max-w-md flex-col gap-3">
        <div v-for="entry in states" :key="entry.name" class="flex flex-col gap-1">
            <h3 class="text-sm text-secondary-text">{{ entry.name }}</h3>
            <StepFlow v-bind="entry.props">
                <p v-if="entry.content" class="text-primary-text">Your height, weight and age set your targets.</p>
            </StepFlow>
        </div>
    </div>
</template>
