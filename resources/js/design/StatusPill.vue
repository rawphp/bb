<script setup lang="ts">
import { computed } from 'vue';
import { toneIcons } from './toneIcons';
import { toneClasses as tones } from './tones';

// One pill for every status, four tones (UI-004). Ported from rp-clone's
// StatusPill.swift. The server sends the text and the tone (CAP-018), so the
// pill never picks a colour itself. Every tone has an icon and the pill always
// carries text, so colour is never the only signal (UI-030).
const props = withDefaults(
    defineProps<{
        // "on track", "315 under", "Adjust foods".
        text: string;
        tone: 'good' | 'neutral' | 'warning' | 'info';
        /** The status is still loading: a pulsing "Loading" pill shows. */
        loading?: boolean;
        /** Why the status could not load; shown in its place. */
        error?: string | null;
        /** The status is server-derived and waiting on the outbox (OFF-008). */
        pending?: boolean;
        disabled?: boolean;
    }>(),
    { loading: false, error: null, pending: false, disabled: false },
);


// Loading and disabled show no decided status, so they stay neutral. An error
// uses the warning tone, as the other components' errors do.
const ready = computed(() => !props.loading && !props.error);
const tone = computed(() => {
    if (props.loading) return 'neutral';
    if (props.error) return 'warning';
    return props.disabled ? 'neutral' : props.tone;
});
const icon = computed(() => {
    if (props.loading) return null;
    if (props.error) return toneIcons.warning;
    return toneIcons[tone.value];
});
const label = computed(() => {
    if (props.loading) return 'Loading';
    if (props.error) return props.error;
    return props.text;
});
</script>

<template>
    <span
        v-if="label"
        :aria-busy="props.loading || undefined"
        :aria-disabled="props.disabled || undefined"
        class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-sm font-semibold"
        :class="[tones[tone], { 'animate-pulse': props.loading, 'opacity-50': props.disabled }]"
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
        <!-- Inserted fresh, so a screen reader announces the error (UI-030). -->
        <span v-if="!props.loading && props.error" role="alert">{{ label }}</span>
        <span v-else>{{ label }}</span>
        <span v-if="ready && props.pending" class="font-normal">· Waiting to sync</span>
        <span v-if="props.disabled" class="sr-only">, unavailable</span>
    </span>
</template>
