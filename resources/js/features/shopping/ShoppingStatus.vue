<script setup lang="ts">
import ReadState from '@/app/ReadState.vue';
import type { ReadFailure } from '@/app/screenRead';
import type { ShoppingShowOutput } from '@/contracts/shopping_show';
import { toneClasses } from '@/design/tones';

// The lines above the shopping list, ported from rp-clone's ShoppingStatus: a failed
// action, then the skeleton or why the list did not load, or the server's empty line.
defineProps<{
    /** The read's answer; undefined until it arrives. */
    data: ShoppingShowOutput | undefined;
    failure: ReadFailure | null;
    error: string | null;
}>();

defineEmits<{ retry: [] }>();
</script>

<template>
    <div class="space-y-3 empty:hidden">
        <p v-if="error" class="rounded-2xl p-4 text-sm" :class="toneClasses.warning" role="alert">{{ error }}</p>
        <ReadState v-if="!data" :failure="failure" what="Your shopping list" @retry="$emit('retry')" />
        <p v-else-if="!data.sections.length && data.empty_text" class="px-1 text-secondary-text">{{ data.empty_text }}</p>
    </div>
</template>
