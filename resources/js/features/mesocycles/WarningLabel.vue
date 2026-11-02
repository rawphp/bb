<script setup lang="ts">
// A warning that never blocks (MESO-021).
defineProps<{ text: string }>();
</script>

<template>
    <p class="flex items-start gap-1.5 text-sm text-tone-warning">
        <svg viewBox="0 0 24 24" class="mt-0.5 size-4 shrink-0" aria-hidden="true" fill="currentColor">
            <path d="M12 3 2 21h20L12 3Zm-1 7h2v5h-2v-5Zm0 7h2v2h-2v-2Z" />
        </svg>
        <span>{{ text }}</span>
    </p>
</template>
