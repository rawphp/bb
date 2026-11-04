<script setup lang="ts">
import ProgressBar from './ProgressBar.vue';

// A macro bar from the server's bar (Cal, P, F or C): each macro keeps its colour everywhere
// (UI-011) and its full name for a screen reader. A label it does not know draws in the accent.
type Bar = { label: string; value_text: string; fraction: number; tone: 'good' | 'neutral' | 'warning' | 'info' };

withDefaults(defineProps<{ bar: Bar; pending?: boolean }>(), { pending: false });

const MACROS: Record<string, { title: string; tint: 'calories' | 'protein' | 'fat' | 'carbs' }> = {
    Cal: { title: 'Calories', tint: 'calories' },
    P: { title: 'Protein', tint: 'protein' },
    F: { title: 'Fat', tint: 'fat' },
    C: { title: 'Carbs', tint: 'carbs' },
};
</script>

<template>
    <ProgressBar
        :title="MACROS[bar.label]?.title ?? bar.label"
        :label="bar.label"
        :value-text="bar.value_text"
        :fraction="bar.fraction"
        :tone="bar.tone"
        :tint="MACROS[bar.label]?.tint ?? 'accent'"
        :pending="pending"
    />
</template>
