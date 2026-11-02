<script setup lang="ts">
import { computed } from 'vue';

// One bar for macro bars and set bars, with a "45/40" label (UI-003). Ported
// from rp-clone's ProgressBar.swift. The server sends the text, the fill and
// whether the value is over target (CAP-001), so the bar does no maths.
const props = defineProps<{
    // Screen-reader name, "Calories" or "Sets".
    title: string;
    // Short label before the bar, "Cal" or "P".
    label: string;
    // "45/40".
    valueText: string;
    // 0 to 1.
    fraction: number;
    tint?: 'calories' | 'protein' | 'fat' | 'carbs' | 'accent';
    overTarget?: boolean;
    state?: 'loading' | 'error' | 'pending';
    disabled?: boolean;
}>();

const tints = {
    calories: { text: 'text-macro-calories', fill: 'bg-macro-calories' },
    protein: { text: 'text-macro-protein', fill: 'bg-macro-protein' },
    fat: { text: 'text-macro-fat', fill: 'bg-macro-fat' },
    carbs: { text: 'text-macro-carbs', fill: 'bg-macro-carbs' },
    accent: { text: 'text-accent', fill: 'bg-accent' },
} as const;

const tint = computed(() =>
    props.disabled ? { text: 'text-secondary-text', fill: 'bg-secondary-text' } : tints[props.tint ?? 'accent'],
);
const ready = computed(() => props.state !== 'loading' && props.state !== 'error');
const width = computed(() => `${Math.min(Math.max(ready.value ? props.fraction : 0, 0), 1) * 100}%`);

const spoken = computed(() => {
    if (props.state === 'loading') return 'Loading';
    if (props.state === 'error') return "Couldn't load";
    const parts = [props.valueText.replace('/', ' of ')];
    if (props.overTarget) parts.push('over target');
    if (props.state === 'pending') parts.push('waiting to sync');
    return parts.join(', ');
});
</script>

<template>
    <div
        role="progressbar"
        :aria-label="props.title"
        :aria-valuetext="spoken"
        :aria-busy="props.state === 'loading' || undefined"
        :aria-disabled="props.disabled || undefined"
        class="flex items-center gap-2 text-sm"
        :class="{ 'opacity-50': props.state === 'pending' || props.disabled }"
    >
        <span aria-hidden="true" class="min-w-7 font-semibold" :class="tint.text">{{ props.label }}</span>
        <span
            aria-hidden="true"
            class="relative h-2 flex-1 overflow-hidden rounded-full bg-secondary-text/20"
            :class="{ 'animate-pulse': props.state === 'loading' }"
        >
            <span class="absolute inset-y-0 left-0 rounded-full" :class="tint.fill" :style="{ width }" />
        </span>
        <svg
            v-if="ready && props.overTarget"
            viewBox="0 0 24 24"
            class="size-4 shrink-0"
            :class="tint.text"
            fill="currentColor"
            aria-hidden="true"
        >
            <path d="M10.3 3.9a2 2 0 0 1 3.4 0l8 13.6A2 2 0 0 1 20 20.5H4a2 2 0 0 1-1.7-3zM11 9v5h2V9zm0 7v2h2v-2z" />
        </svg>
        <span aria-hidden="true" class="shrink-0 whitespace-nowrap tabular-nums">
            <template v-if="props.state === 'loading'">Loading</template>
            <template v-else-if="props.state === 'error'">Couldn't load</template>
            <template v-else>{{ props.valueText }}</template>
        </span>
        <span v-if="props.state === 'pending'" aria-hidden="true" class="shrink-0 text-secondary-text">
            Waiting to sync
        </span>
    </div>
</template>
