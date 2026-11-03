<script setup lang="ts">
import type { TodayShowOutput } from '@/contracts/today_show';
import ItemCard from '@/design/ItemCard.vue';

// SCH-032: an Other activity at its start time, with Edit and Delete on a long press.
// Ported from rp-clone's TodayOtherActivityCard.
defineProps<{ activity: TodayShowOutput['other_activities'][number] }>();

const emit = defineEmits<{ menu: [] }>();
</script>

<template>
    <div data-test="activity-card" @contextmenu.prevent="emit('menu')">
        <ItemCard :title="activity.name" :time="activity.time_text">
            <template #icon>
                <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="14" cy="4.5" r="1.75" />
                    <path d="M7 21l3.5-6 3 2.5V22M9.5 11l2-3.5 3.5 2 2.5 3M6 10l3-2.5" />
                </svg>
            </template>
            <p class="text-sm text-secondary-text">{{ activity.intensity_text }}</p>
        </ItemCard>
    </div>
</template>
