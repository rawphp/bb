<script setup lang="ts">
// MESO-030: a priority as filled bars out of three. The server sends how many are
// filled: Emphasize 3, Grow 2, Maintain 1, Off 0 (MESO-034).
const TOTAL = 3;

defineProps<{ bars: number }>();
</script>

<template>
    <span class="flex items-center gap-[3px]" role="img" :aria-label="`${bars} of ${TOTAL} bars`">
        <span
            v-for="index in TOTAL"
            :key="index"
            class="h-4 w-1.5 rounded-full"
            :class="index <= bars ? 'bg-accent' : 'bg-secondary-text/25'"
            data-test="bar"
        />
    </span>
</template>
