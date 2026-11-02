<script setup lang="ts">
import ProgressBar from '../ProgressBar.vue';

// Macro and set bars in each VIEW-004 state.
const protein = { title: 'Protein', label: 'P', valueText: '51/170', fraction: 0.3, tint: 'protein' } as const;

const states = [
    { name: 'default', props: protein },
    { name: 'over target', props: { title: 'Sets', label: 'Sets', valueText: '5/4', fraction: 1, tone: 'warning' } },
    { name: 'empty', props: { title: 'Calories', label: 'Cal', valueText: '0/2600', fraction: 0, tint: 'calories' } },
    { name: 'loading', props: { ...protein, loading: true } },
    { name: 'error', props: { ...protein, error: "Couldn't load" } },
    { name: 'pending', props: { ...protein, pending: true } },
    { name: 'disabled', props: { ...protein, disabled: true } },
] as const;
</script>

<template>
    <div class="flex max-w-md flex-col gap-3">
        <div v-for="entry in states" :key="entry.name" class="flex flex-col gap-1 rounded-lg bg-surface p-3">
            <h3 class="text-sm text-secondary-text">{{ entry.name }}</h3>
            <ProgressBar v-bind="entry.props" />
        </div>
    </div>
</template>
