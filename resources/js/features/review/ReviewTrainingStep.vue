<script setup lang="ts">
import type { ReviewShowOutput } from '@/contracts/review_show';
import StatusPill from '@/design/StatusPill.vue';
import ReviewLine from './ReviewLine.vue';
import ReviewNote from './ReviewNote.vue';

type Training = NonNullable<ReviewShowOutput['training']>;

// Step 3 (REV-003): sessions done, skipped and missed, sets against planned, the
// underperformance, volume-ceiling and joint-pain flags, and what next week is.
// `pending` dims the server's numbers while a write waits to sync (OFF-008).
defineProps<{ training: Training; pending: boolean }>();
</script>

<template>
    <div class="flex flex-col gap-3" data-test="step-training">
        <ul data-test="lines" class="flex flex-col gap-3" :class="{ 'opacity-60': pending }">
            <ReviewLine v-for="line in training.lines" :key="line.label" v-bind="line" />
        </ul>
        <ReviewNote v-for="note in training.notes" :key="note.text" v-bind="note" />
        <p data-test="next-week" class="flex min-h-8 items-center justify-between gap-2">
            <span class="text-primary-text">{{ training.next_week_label }}</span>
            <StatusPill :text="training.next_week.text" :tone="training.next_week.tone" :pending="pending" />
        </p>
    </div>
</template>
