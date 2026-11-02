<script setup lang="ts">
import Skeleton from '@/design/Skeleton.vue';
import type { ReadFailure } from './useRead';

// What a screen shows while its read has no data: a skeleton, or why it failed (CAP-004).
defineProps<{ failure: ReadFailure | null; what: string }>();
defineEmits<{ retry: [] }>();
</script>

<template>
    <div v-if="failure === 'signed-out'" class="rounded-2xl bg-surface p-4">You are signed out.</div>
    <div v-else-if="failure === 'error'" class="space-y-3 rounded-2xl bg-surface p-4" role="alert">
        <p>{{ what }} could not be loaded.</p>
        <button type="button" class="min-h-tap rounded-xl border px-4 text-accent" data-test="retry" @click="$emit('retry')">Try again</button>
    </div>
    <Skeleton v-else shape="row" :count="3" data-test="skeleton" />
</template>
