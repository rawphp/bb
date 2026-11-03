<script setup lang="ts">
import { toneIcons } from '@/design/toneIcons';
import { toneClasses } from '@/design/tones';

// A short block of words with its tone's icon, as the server sends it (CAP-018).
// rp-clone's inline `Note` in ReviewSteps.swift.
defineProps<{ text: string; tone: 'good' | 'neutral' | 'warning' | 'info' }>();
</script>

<template>
    <p data-test="note" class="flex items-start gap-2 rounded-[10px] p-2.5 text-sm font-semibold" :class="toneClasses[tone]">
        <svg aria-hidden="true" viewBox="0 0 24 24" class="mt-0.5 size-4 shrink-0" fill="currentColor"><path :d="toneIcons[tone]" /></svg>
        <span>{{ text }}</span>
    </p>
</template>
