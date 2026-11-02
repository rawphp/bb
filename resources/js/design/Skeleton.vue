<script setup lang="ts">
// Loading placeholders in the shape of the content; there is no spinner anywhere (PWA-024).
withDefaults(
    defineProps<{
        shape: 'row' | 'card' | 'bar';
        /** How many to draw, for a list of rows. */
        count?: number;
        /** Read once by screen readers for the whole group (UI-030). */
        label?: string;
    }>(),
    { count: 1, label: 'Loading' },
);

const shapes = {
    row: 'min-h-tap w-full rounded-xl',
    card: 'h-32 w-full rounded-2xl',
    bar: 'h-2 w-full rounded-full',
};
</script>

<template>
    <div v-if="count > 0" role="status" class="flex flex-col gap-2">
        <span class="sr-only">{{ label }}</span>
        <div
            v-for="n in count"
            :key="n"
            data-placeholder
            aria-hidden="true"
            class="animate-pulse bg-secondary-text/15 motion-reduce:animate-none"
            :class="shapes[shape]"
        />
    </div>
</template>
