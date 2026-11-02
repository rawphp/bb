<script setup lang="ts">
import type { ReadFailure } from '@/app/screenRead';
import OfflineNotice from '@/design/OfflineNotice.vue';
import Skeleton from '@/design/Skeleton.vue';
import { toneClasses } from '@/design/tones';

// What a screen shows while its useRead() has no data: a skeleton, or why it failed.
defineProps<{ failure: ReadFailure | null; what: string }>();
defineEmits<{ retry: [] }>();
</script>

<template>
    <OfflineNotice v-if="failure?.offline" :needs="`${what} will load when you're back online.`" />
    <div v-else-if="failure" class="space-y-3 rounded-2xl p-4" :class="toneClasses.warning" role="alert">
        <p>{{ failure.error }}</p>
        <!-- A gone session needs a sign-in, not another read. -->
        <button
            v-if="!failure.signedOut"
            type="button"
            class="min-h-tap rounded-xl border border-secondary-text/25 px-4 active:opacity-70"
            data-test="retry"
            @click="$emit('retry')"
        >
            Try again
        </button>
    </div>
    <Skeleton v-else shape="row" :count="3" data-test="skeleton" />
</template>
