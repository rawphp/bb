<script setup lang="ts">
// One filter chip on the Pantry: a capsule that fills with the accent while its filter is on.
// Ported from rp-clone's PantryChip. The control that opens it wraps it.
defineProps<{ title: string; active: boolean }>();
</script>

<template>
    <span
        class="inline-flex min-h-tap items-center gap-1 whitespace-nowrap rounded-full px-3 text-sm font-medium"
        :class="active ? 'bg-accent text-on-accent' : 'bg-surface text-primary-text'"
    >
        {{ title }}
        <svg aria-hidden="true" viewBox="0 0 24 24" class="size-3" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
            <path d="m6 9 6 6 6-6" />
        </svg>
    </span>
</template>
