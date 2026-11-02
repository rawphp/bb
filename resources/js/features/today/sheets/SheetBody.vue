<script setup lang="ts">
import ReadState from '@/app/ReadState.vue';
import type { ReadFailure } from '@/app/screenRead';

// A sheet's content: its sections on the grouped background once the read has an answer,
// else the skeleton or why the read failed (ReadState).
defineProps<{ loaded: boolean; failure: ReadFailure | null; what: string }>();
defineEmits<{ retry: [] }>();
</script>

<template>
    <div class="flex min-h-full flex-col gap-6 bg-background p-4">
        <slot v-if="loaded" />
        <ReadState v-else :failure="failure" :what="what" @retry="$emit('retry')" />
    </div>
</template>
