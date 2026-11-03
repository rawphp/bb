<script setup lang="ts">
import { toneText } from '@/design/toneText';

// A line of a review step: a label on the left, the server's value on the right in its
// tone (CAP-018). rp-clone's inline `Line` in ReviewSteps.swift.
defineProps<{ label: string; value: string; tone: 'good' | 'neutral' | 'warning' | 'info' }>();
</script>

<template>
    <li data-test="line" class="flex min-h-8 items-center justify-between gap-2">
        <span class="text-primary-text">{{ label }}</span>
        <span class="text-right tabular-nums" :class="toneText[tone] || 'text-primary-text'">{{ value }}</span>
    </li>
</template>
