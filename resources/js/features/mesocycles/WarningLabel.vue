<script setup lang="ts">
import { toneIcons } from '@/design/toneIcons';

// A warning that never blocks (MESO-021).
defineProps<{ text: string }>();
</script>

<template>
    <p class="flex items-start gap-1.5 text-sm text-tone-warning">
        <svg viewBox="0 0 24 24" class="mt-0.5 size-4 shrink-0" aria-hidden="true" fill="currentColor">
            <path :d="toneIcons.warning" />
        </svg>
        <span>{{ text }}</span>
    </p>
</template>
