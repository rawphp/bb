<script setup lang="ts">
import StatusPill from '../StatusPill.vue';

// Each tone, then each VIEW-004 state.
const states = [
    { name: 'good', props: { text: 'on track', tone: 'good' } },
    { name: 'neutral', props: { text: 'Deload', tone: 'neutral' } },
    { name: 'warning', props: { text: 'Adjust foods', tone: 'warning' } },
    { name: 'info', props: { text: '315 under', tone: 'info' } },
    { name: 'empty', props: { text: '', tone: 'neutral' } },
    { name: 'loading', props: { text: 'on track', tone: 'good', loading: true } },
    { name: 'error', props: { text: 'on track', tone: 'good', error: "Couldn't load" } },
    { name: 'pending', props: { text: 'Targets met', tone: 'good', pending: true } },
    { name: 'disabled', props: { text: 'on track', tone: 'good', disabled: true } },
] as const;
</script>

<template>
    <div class="flex max-w-md flex-col gap-3">
        <div v-for="entry in states" :key="entry.name" class="flex flex-col items-start gap-1 rounded-lg bg-surface p-3">
            <h3 class="text-sm text-secondary-text">{{ entry.name }}</h3>
            <StatusPill v-bind="entry.props" />
        </div>
    </div>
</template>
