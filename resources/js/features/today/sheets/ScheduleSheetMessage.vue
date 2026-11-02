<script setup lang="ts">
import { toneIcons } from '@/design/toneIcons';

// Why a schedule sheet did not close: the server's words for a refused save (CAP-012).
// Ported from rp-clone's ScheduleSheetMessage.
defineProps<{ text: string | null }>();
</script>

<template>
    <p v-if="text" role="alert" class="flex items-start gap-2 px-4 text-sm text-tone-warning">
        <svg aria-hidden="true" viewBox="0 0 24 24" class="mt-0.5 size-4 shrink-0" fill="currentColor"><path :d="toneIcons.warning" /></svg>
        {{ text }}
    </p>
</template>
