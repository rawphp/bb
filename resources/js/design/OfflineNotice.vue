<script setup lang="ts">
// What a screen or feature shows when it needs the network and has none (OFF-003, OFF-031).
// The app re-reads on reconnect (OFF-004), so there is no retry button.
defineProps<{
    /** What the screen needs, in plain words: "Food search needs a connection." */
    needs?: string;
}>();
</script>

<template>
    <div role="status" class="flex gap-3 rounded-xl border border-secondary-text/25 bg-surface p-4">
        <svg
            aria-hidden="true"
            viewBox="0 0 24 24"
            class="mt-0.5 size-5 shrink-0 text-secondary-text"
            fill="none"
            stroke="currentColor"
            stroke-width="1.75"
            stroke-linecap="round"
        >
            <path d="M7 18h10a4 4 0 0 0 1.6-7.7A6 6 0 0 0 8.2 7.6" />
            <path d="M5.2 9.6A4.5 4.5 0 0 0 7 18" />
            <path d="M3 3l18 18" />
        </svg>
        <div class="flex min-w-0 flex-col gap-0.5">
            <p data-title class="text-base font-semibold text-primary-text">Not available offline</p>
            <p v-if="needs" data-needs class="text-sm text-secondary-text">{{ needs }}</p>
        </div>
    </div>
</template>
