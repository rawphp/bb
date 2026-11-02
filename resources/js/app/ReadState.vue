<script setup lang="ts">
import type { ReadFailure } from '@/app/useRead';
import OfflineNotice from '@/design/OfflineNotice.vue';
import Skeleton from '@/design/Skeleton.vue';
import { toneClasses } from '@/design/tones';

// What a screen shows while its useRead() has no data: a placeholder, or why it failed.
// A screen whose content has its own shape passes its placeholder in the loading slot.
defineProps<{ failure: ReadFailure | null; what: string }>();
defineEmits<{ retry: [] }>();
</script>

<template>
    <p v-if="failure === 'signed-out'" role="alert" class="rounded-2xl p-4" :class="toneClasses.warning">You are signed out.</p>
    <OfflineNotice v-else-if="failure === 'offline'" :needs="`${what} will load when you're back online.`" />
    <div v-else-if="failure === 'error'" class="space-y-3 rounded-2xl p-4" :class="toneClasses.warning" role="alert">
        <p>{{ what }} could not be loaded.</p>
        <button type="button" class="min-h-tap rounded-xl border border-secondary-text/25 px-4 active:opacity-70" data-test="retry" @click="$emit('retry')">
            Try again
        </button>
    </div>
    <slot v-else name="loading">
        <Skeleton shape="row" :count="3" data-test="skeleton" />
    </slot>
</template>
