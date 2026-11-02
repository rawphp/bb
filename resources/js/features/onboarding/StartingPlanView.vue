<script setup lang="ts">
// Shown while "Start my plan" works out the targets and saves the plan (ONB-020), in
// the server's words. Ported from rp-clone's ReviewStep.
defineProps<{ title: string; text: string }>();
</script>

<template>
    <div role="status" aria-live="polite" class="fixed inset-0 z-10 flex flex-col items-center justify-center gap-4 bg-background p-6 text-center">
        <svg aria-hidden="true" viewBox="0 0 24 24" class="size-9 animate-spin text-secondary-text" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <path d="M12 3a9 9 0 109 9" />
        </svg>
        <p class="text-xl font-semibold text-primary-text">{{ title }}</p>
        <p class="text-sm text-secondary-text">{{ text }}</p>
    </div>
</template>
