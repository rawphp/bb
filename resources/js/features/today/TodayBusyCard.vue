<script setup lang="ts">
import type { TodayShowOutput } from '@/contracts/today_show';
import ItemCard from '@/design/ItemCard.vue';

// SCH-005: a busy period, greyed and without a status control. Ported from rp-clone's TodayBusyCard.
defineProps<{ busy: TodayShowOutput['busy_periods'][number] }>();
</script>

<template>
    <div data-test="busy-card" class="opacity-60" role="group" :aria-label="`Busy: ${busy.label}, ${busy.time_text}`">
        <ItemCard :title="busy.label" :time="busy.time_text">
            <template #icon>
                <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round">
                    <rect x="3" y="7" width="18" height="13" rx="2" />
                    <path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M3 12h18" />
                </svg>
            </template>
        </ItemCard>
    </div>
</template>
