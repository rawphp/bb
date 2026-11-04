<script setup lang="ts">
import { computed } from 'vue';
import type { TodayShowOutput } from '@/contracts/today_show';

// SCH-012: where the user is in their day. The server places the line on today's timeline
// and names the next item not yet done; this only draws it. Read aloud as one note.
const props = defineProps<{ now: NonNullable<TodayShowOutput['now']> }>();

const spoken = computed(() => (props.now.next_text ? `${props.now.label}. ${props.now.next_text}` : props.now.label));
</script>

<template>
    <div role="note" :aria-label="spoken" data-test="now-line" class="flex items-center gap-2 px-1 py-0.5">
        <span data-test="now-label" class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-accent px-2.5 py-1 text-xs font-bold tracking-wide text-on-accent uppercase">
            <span class="size-1.5 rounded-full bg-on-accent" aria-hidden="true" />
            {{ now.label }}
        </span>
        <span class="h-0.5 min-w-4 flex-1 rounded-full bg-accent" aria-hidden="true" />
        <span v-if="now.next_text" data-test="now-next" class="min-w-0 truncate text-sm font-semibold text-accent">{{ now.next_text }}</span>
    </div>
</template>
