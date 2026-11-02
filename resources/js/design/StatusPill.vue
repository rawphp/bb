<script setup lang="ts">
import { computed } from 'vue';

// One pill for every status, four tones (UI-004). Ported from rp-clone's
// StatusPill.swift. The server sends the text and the tone (CAP-018), so the
// pill never picks a colour itself. Every tone has an icon and the pill always
// carries text, so colour is never the only signal (UI-030).
const props = defineProps<{
    // "on track", "315 under", "Adjust foods".
    text: string;
    tone: 'good' | 'neutral' | 'warning' | 'info';
    state?: 'loading' | 'error' | 'pending';
    disabled?: boolean;
}>();

// Whole class names, so Tailwind finds every token utility in this file.
const tones = {
    good: 'text-tone-good bg-tone-good-bg',
    neutral: 'text-tone-neutral bg-tone-neutral-bg',
    warning: 'text-tone-warning bg-tone-warning-bg',
    info: 'text-tone-info bg-tone-info-bg',
} as const;

// checkmark.circle.fill, minus.circle.fill, exclamationmark.triangle.fill, info.circle.fill.
const circle = 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z';
const icons = {
    good: `${circle}m-1.2 14.2-4-4 1.4-1.4 2.6 2.6 5.6-5.6 1.4 1.4z`,
    neutral: `${circle}M7 11h10v2H7z`,
    warning: 'M10.3 3.9a2 2 0 0 1 3.4 0l8 13.6A2 2 0 0 1 20 20.5H4a2 2 0 0 1-1.7-3zM11 9v5h2V9zm0 7v2h2v-2z',
    info: `${circle}m-1 5h2v2h-2zm0 4h2v6h-2z`,
} as const;

// Loading, error and disabled have no decided status yet, so they stay neutral.
const decided = computed(() => !props.state || props.state === 'pending');
const tone = computed(() => (decided.value && !props.disabled ? props.tone : 'neutral'));
const icon = computed(() => {
    if (props.state === 'loading') return null;
    if (props.state === 'error') return icons.warning;
    return icons[props.tone];
});
const label = computed(() => {
    if (props.state === 'loading') return 'Loading';
    if (props.state === 'error') return "Couldn't load";
    return props.text;
});
</script>

<template>
    <span
        v-if="props.text || !decided"
        class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-sm font-semibold"
        :class="[tones[tone], { 'animate-pulse': props.state === 'loading', 'opacity-50': props.disabled }]"
    >
        <svg
            v-if="icon"
            viewBox="0 0 24 24"
            class="size-[1em] shrink-0"
            fill="currentColor"
            fill-rule="evenodd"
            aria-hidden="true"
        >
            <path :d="icon" />
        </svg>
        <span>{{ label }}</span>
        <span v-if="props.state === 'pending'" class="font-normal">· Waiting to sync</span>
        <span v-if="props.disabled" class="sr-only">, unavailable</span>
    </span>
</template>
