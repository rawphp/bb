<script setup lang="ts">
import type { ReviewShowOutput } from '@/contracts/review_show';
import StatusPill from '@/design/StatusPill.vue';
import ReviewLine from './ReviewLine.vue';

type Progress = NonNullable<ReviewShowOutput['progress']>;

// Step 4 (REV-003): trend weight against the phase's goal range, and the week's rate
// against target, each judged by the server in a pill. `pending` dims the numbers (OFF-008).
defineProps<{ progress: Progress; pending: boolean }>();
</script>

<template>
    <div class="flex flex-col gap-3" data-test="step-progress">
        <p v-if="progress.empty_message" class="text-sm text-secondary-text">{{ progress.empty_message }}</p>
        <template v-else>
            <ul data-test="lines" class="flex flex-col gap-3" :class="{ 'opacity-60': pending }">
                <ReviewLine v-for="line in progress.lines" :key="line.label" v-bind="line" />
            </ul>
            <p v-if="progress.pills.length" data-test="pills" class="flex flex-wrap gap-2">
                <StatusPill v-for="pill in progress.pills" :key="pill.text" :text="pill.text" :tone="pill.tone" :pending="pending" />
            </p>
        </template>
    </div>
</template>
