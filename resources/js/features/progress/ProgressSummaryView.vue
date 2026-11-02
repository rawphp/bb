<script setup lang="ts">
import type { ProgressShowOutput } from '@/contracts/progress_show';
import { toneText } from './toneText';

// Start weight, change so far and goal weight (ANA-002), worded by the server.
defineProps<{ summary: ProgressShowOutput['summary']; pending?: boolean }>();
</script>

<template>
    <dl data-summary class="flex items-start" :class="{ 'opacity-50': pending }">
        <div v-for="figure in summary" :key="figure.label" class="flex min-w-0 flex-1 flex-col gap-0.5">
            <dt class="text-sm text-secondary-text">{{ figure.label }}</dt>
            <dd class="text-lg font-semibold tabular-nums" :class="toneText[figure.tone]">{{ figure.value }}</dd>
        </div>
    </dl>
</template>
