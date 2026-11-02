<script setup lang="ts">
import ReadState from '@/app/ReadState.vue';
import type { ReadFailure } from '@/app/screenRead';
import { toneClasses } from '@/design/tones';

// The lines above a saved meals list, ported from rp-clone's SavedMealsStatus: a failed
// action, then the skeleton or why the list did not load, or the server's empty line.
defineProps<{
    loaded: boolean;
    failure: ReadFailure | null;
    /** The server's line for an empty list; null while the list has meals. */
    emptyText: string | null;
    error: string | null;
}>();

defineEmits<{ retry: [] }>();
</script>

<template>
    <div class="space-y-3 empty:hidden">
        <p v-if="error" class="rounded-2xl p-4 text-sm" :class="toneClasses.warning" role="alert">{{ error }}</p>
        <ReadState v-if="!loaded" :failure="failure" what="Your saved meals" @retry="$emit('retry')" />
        <p v-else-if="emptyText" class="px-1 text-secondary-text">{{ emptyText }}</p>
    </div>
</template>
