<script setup lang="ts">
import type { WeekShowOutput } from '@/contracts/week_show';
import { toneClasses } from '@/design/tones';
import { toneIcons } from '@/design/toneIcons';

// The notes under Today's week strip, such as "Too much cardio" (SCH-062). The server decides
// each one and its tone; they only inform, so they are a status, not an alert.
defineProps<{ warnings: WeekShowOutput['warnings'] }>();
</script>

<template>
    <div v-if="warnings.length" class="flex flex-col gap-2" data-test="week-warnings">
        <p v-for="warning in warnings" :key="warning.text" role="status" class="flex items-start gap-2 rounded-xl p-3 text-sm" :class="toneClasses[warning.tone]">
            <svg aria-hidden="true" viewBox="0 0 24 24" class="mt-0.5 size-4 shrink-0" fill="currentColor"><path :d="toneIcons[warning.tone]" fill-rule="evenodd" /></svg>
            {{ warning.text }}
        </p>
    </div>
</template>
