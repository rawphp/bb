<script setup lang="ts">
import type { ReadFailure } from '@/app/useRead';

// What a Settings page shows while its read has no data: a skeleton, or why it failed.
defineProps<{ failure: ReadFailure | null; what: string }>();
defineEmits<{ retry: [] }>();
</script>

<template>
    <div v-if="failure === 'signed-out'" class="rounded-2xl bg-surface p-4">You are signed out.</div>
    <div v-else-if="failure === 'error'" class="space-y-3 rounded-2xl bg-surface p-4" role="alert">
        <p>{{ what }} could not be loaded.</p>
        <button type="button" class="min-h-tap rounded-xl border px-4" data-test="retry" @click="$emit('retry')">Try again</button>
    </div>
    <div v-else class="space-y-3" aria-busy="true" aria-label="Loading" data-test="skeleton">
        <div v-for="row in 3" :key="row" class="h-tap animate-pulse rounded-2xl bg-surface" />
    </div>
</template>
