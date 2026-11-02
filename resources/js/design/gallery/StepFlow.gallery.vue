<script setup lang="ts">
import StepFlow, { type StepFlowStep } from '../StepFlow.vue';

// The step flow in each VIEW-004 state.
const step: StepFlowStep = {
    counter: 'Step 2 of 4',
    title: 'About you',
    next_title: 'Next',
    can_go_back: true,
    can_continue: true,
};

const states = [
    { name: 'default', props: { step }, content: true },
    {
        name: 'last step',
        props: { step: { ...step, counter: 'Step 4 of 4', title: 'Review', next_title: 'Done' } },
        content: true,
    },
    {
        name: 'empty',
        props: {
            step: {
                ...step,
                counter: 'Step 1 of 4',
                can_go_back: false,
                can_continue: false,
                continue_hint: 'Pick a goal to continue.',
            },
        },
        content: false,
    },
    { name: 'loading', props: { step, loading: true }, content: true },
    { name: 'error', props: { step, error: "Couldn't load this step" }, content: true },
    { name: 'pending', props: { step, pending: true }, content: true },
    { name: 'disabled', props: { step, disabled: true }, content: true },
];
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
