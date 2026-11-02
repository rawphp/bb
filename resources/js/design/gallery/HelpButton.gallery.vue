<script setup lang="ts">
import HelpButton from '../HelpButton.vue';

// The ? icon in each VIEW-004 state. Tap one to open its sheet.
const title = 'Calorie target';
const message =
    'Your daily calories come from your weight, activity and goal. Eating to this number moves you toward your goal at a steady, safe rate.';

const states = [
    { name: 'default', props: { title, message } },
    { name: 'empty', props: { title, message: '' } },
    { name: 'loading', props: { title, message, loading: true } },
    { name: 'error', props: { title, message, error: "Couldn't load this help." } },
    { name: 'pending', props: { title, message, pending: true } },
    { name: 'disabled', props: { title, message, disabled: true } },
] as const;
</script>

<template>
    <div class="flex flex-wrap gap-4">
        <div v-for="entry in states" :key="entry.name" class="flex items-center gap-1 rounded-lg bg-surface px-3">
            <h3 class="text-sm text-secondary-text">{{ entry.name }}</h3>
            <HelpButton v-bind="entry.props" />
        </div>
    </div>
</template>
