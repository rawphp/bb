<script setup lang="ts">
import { computed } from 'vue';
import { toneIcons } from './toneIcons';

// One bar for macro bars and set bars, with a "45/40" label (UI-003). Ported
// from rp-clone's ProgressBar.swift. The server sends the text, the fill and
// the tone (CAP-001, CAP-018), so the bar does no maths. A `warning` tone is
// the Swift bar's over-target state: a warning icon and "over target".
const props = withDefaults(
    defineProps<{
        // Screen-reader name, "Calories" or "Sets".
        title: string;
        // Short label before the bar, "Cal" or "P". None for a bar its row already names.
        label?: string;
        // "45/40".
        valueText: string;
        // 0 to 1.
        fraction: number;
        // The bar's tone from the server's payload (UI-004).
        tone?: 'good' | 'neutral' | 'warning' | 'info';
        tint?: 'calories' | 'protein' | 'fat' | 'carbs' | 'accent';
        /** The value is still loading: a pulsing empty track shows. */
        loading?: boolean;
        /** Why the value could not load; shown in its place. */
        error?: string | null;
        /** The value is server-derived and waiting on the outbox (OFF-008). */
        pending?: boolean;
        disabled?: boolean;
    }>(),
    { label: '', tone: 'neutral', tint: 'accent', loading: false, error: null, pending: false, disabled: false },
);

const tints = {
    calories: { text: 'text-macro-calories', fill: 'bg-macro-calories' },
    protein: { text: 'text-macro-protein', fill: 'bg-macro-protein' },
    fat: { text: 'text-macro-fat', fill: 'bg-macro-fat' },
    carbs: { text: 'text-macro-carbs', fill: 'bg-macro-carbs' },
    accent: { text: 'text-accent', fill: 'bg-accent' },
} as const;

const tint = computed(() =>
    props.disabled ? { text: 'text-secondary-text', fill: 'bg-secondary-text' } : tints[props.tint],
);
const ready = computed(() => !props.loading && !props.error);
const overTarget = computed(() => ready.value && props.tone === 'warning');
const syncing = computed(() => ready.value && props.pending);
const width = computed(() => `${Math.min(Math.max(ready.value ? props.fraction : 0, 0), 1) * 100}%`);

const spoken = computed(() => {
    if (props.loading) return 'Loading';
    if (props.error) return props.error;
    const parts = [props.valueText.replace('/', ' of ')];
    if (overTarget.value) parts.push('over target');
    if (syncing.value) parts.push('waiting to sync');
    return parts.join(', ');
});
</script>

<template>
    <div
        role="progressbar"
        :aria-label="props.title"
        :aria-valuetext="spoken"
        :aria-busy="props.loading || undefined"
        :aria-disabled="props.disabled || undefined"
        class="flex items-center gap-2 text-sm"
        :class="{ 'opacity-50': props.disabled }"
    >
        <span v-if="props.label" aria-hidden="true" class="min-w-7 font-semibold" :class="[tint.text, { 'opacity-50': syncing }]">
            {{ props.label }}
        </span>
        <span
            aria-hidden="true"
            class="relative h-2 flex-1 overflow-hidden rounded-full bg-secondary-text/20"
            :class="{ 'animate-pulse': props.loading, 'opacity-50': syncing }"
        >
            <span class="absolute inset-y-0 left-0 rounded-full" :class="tint.fill" :style="{ width }" />
        </span>
        <svg
            v-if="overTarget"
            viewBox="0 0 24 24"
            class="size-4 shrink-0"
            :class="[tint.text, { 'opacity-50': syncing }]"
            fill="currentColor"
            aria-hidden="true"
        >
            <path :d="toneIcons.warning" />
        </svg>
        <span v-if="props.loading" aria-hidden="true" class="shrink-0 whitespace-nowrap">Loading</span>
        <span v-else-if="props.error" aria-hidden="true" class="shrink-0 text-tone-warning">{{ props.error }}</span>
        <span v-else aria-hidden="true" class="shrink-0 whitespace-nowrap tabular-nums" :class="{ 'opacity-50': syncing }">
            {{ props.valueText }}
        </span>
        <span v-if="syncing" aria-hidden="true" class="shrink-0 text-secondary-text">Waiting to sync</span>
    </div>
</template>
