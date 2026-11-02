<script setup lang="ts">
import type { ReadFailure } from '@/app/useRead';
import Skeleton from '@/design/Skeleton.vue';

// What a Settings page shows while its read has no data: a skeleton, or why it failed.
defineProps<{ failure: ReadFailure | null; what: string }>();
defineEmits<{ retry: [] }>();
</script>

<template>
    <div v-if="failure === 'signed-out'" class="rounded-2xl bg-surface p-4">You are signed out.</div>
    <div v-else-if="failure === 'error'" class="space-y-3 rounded-2xl bg-surface p-4" role="alert">
        <p>{{ what }} could not be loaded.</p>
        <button type="button" class="min-h-tap rounded-xl border border-secondary-text/25 px-4 active:opacity-70" data-test="retry" @click="$emit('retry')">
            Try again
        </button>
    </div>
    <Skeleton v-else shape="row" :count="3" data-test="skeleton" />
</template>
