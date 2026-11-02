<script setup lang="ts">
import { toneIcons } from '@/design/toneIcons';

// A warning that never blocks (SAFE-018): the macro and the fix, in the warning tone.
// The server writes the words (CAP-018).
defineProps<{ text: string }>();
</script>

<template>
    <p class="flex items-start gap-2 text-sm font-semibold text-tone-warning" data-test="warning">
        <svg aria-hidden="true" viewBox="0 0 24 24" class="mt-0.5 size-4 shrink-0" fill="currentColor"><path :d="toneIcons.warning" /></svg>
        <span>{{ text }}</span>
    </p>
</template>
