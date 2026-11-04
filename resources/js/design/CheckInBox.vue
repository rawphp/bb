<script setup lang="ts">
import { checkMarks, type CheckInShape } from './checkInLook';

// The check-in box itself (MEAL-047, UI-030): an open box, or a filled one with a check or an
// arrow. It takes the colour of its text (checkInLook's color); the button around it, if any,
// is the caller's.
defineProps<{ shape: CheckInShape }>();
</script>

<template>
    <svg viewBox="0 0 24 24" class="size-7" aria-hidden="true" data-test="check-in-box" :data-shape="shape">
        <rect x="3.5" y="3.5" width="17" height="17" rx="3.5" stroke="currentColor" stroke-width="1.75" :fill="shape === 'empty' ? 'none' : 'currentColor'" />
        <path
            v-if="shape !== 'empty'"
            :d="checkMarks[shape]"
            class="stroke-on-accent"
            fill="none"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
    </svg>
</template>
