<script setup lang="ts">
import ReadState from '@/app/ReadState.vue';
import type { ReadFailure } from '@/app/screenRead';
import type { SavedMealsShowOutput } from '@/contracts/saved_meals_show';
import { toneClasses } from '@/design/tones';

// The lines above a saved meals list, ported from rp-clone's SavedMealsStatus: a failed
// action, then the skeleton or why the list did not load, or the server's empty line.
defineProps<{
    /** The read's answer; undefined until it arrives. */
    data: SavedMealsShowOutput | undefined;
    failure: ReadFailure | null;
    error: string | null;
}>();

defineEmits<{ retry: [] }>();
</script>

<template>
    <div class="space-y-3 empty:hidden">
        <p v-if="error" class="rounded-2xl p-4 text-sm" :class="toneClasses.warning" role="alert">{{ error }}</p>
        <ReadState v-if="!data" :failure="failure" what="Your saved meals" @retry="$emit('retry')" />
        <p v-else-if="!data.meals.length && data.empty_text" class="px-1 text-secondary-text">{{ data.empty_text }}</p>
    </div>
</template>
