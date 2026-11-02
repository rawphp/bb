<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { entries, syncedAt } from '@/app/outbox';

// The More menu's sync line (ACC-003, OFF-010): when the device last synced, or how many
// changes wait in the outbox. It opens the outbox, which says which couldn't sync.

const ago = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });
const UNITS = [
    ['day', 86_400],
    ['hour', 3_600],
    ['minute', 60],
] as const;

// The relative time ticks over while the line is on screen.
const clock = ref(Date.now());
let ticker: ReturnType<typeof setInterval> | undefined;
onMounted(() => (ticker = setInterval(() => (clock.value = Date.now()), 30_000)));
onUnmounted(() => clearInterval(ticker));

function synced(at: number): string {
    const seconds = Math.max(0, Math.round((clock.value - at) / 1000));
    const unit = UNITS.find(([, size]) => seconds >= size);

    return unit === undefined ? 'Synced a few seconds ago' : `Synced ${ago.format(-Math.floor(seconds / unit[1]), unit[0])}`;
}

const status = computed(() => {
    const count = entries.value.length;
    if (count > 0) return `${count} ${count === 1 ? 'change' : 'changes'} waiting to sync`;

    return syncedAt.value === null ? 'Not synced yet' : synced(syncedAt.value);
});
</script>

<template>
    <RouterLink to="/sync" class="min-h-tap flex items-center gap-3 rounded-2xl bg-surface px-4 py-3 active:opacity-70" data-test="sync-line">
        <span class="flex-1 font-medium">Sync</span>
        <span class="text-sm text-secondary-text">{{ status }}</span>
        <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4 shrink-0 text-secondary-text" fill="none" stroke="currentColor" stroke-width="2">
            <path d="m9 6 6 6-6 6" />
        </svg>
    </RouterLink>
</template>
