<script setup lang="ts">
import type { ProgressShowOutput } from '@/contracts/progress_show';
import ItemCard from '@/design/ItemCard.vue';
import StatusPill from '@/design/StatusPill.vue';
import { toneText } from './toneText';

// One past mesocycle: dates, phase and average weekly sets per muscle (ANA-007).
defineProps<{ entry: ProgressShowOutput['mesocycle_history'][number]; pending?: boolean }>();
</script>

<template>
    <ItemCard data-mesocycle-history :title="entry.title" :pending="pending">
        <template #icon>
            <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round">
                <rect x="2.5" y="8" width="3" height="8" rx="1" />
                <rect x="5.5" y="6" width="3" height="12" rx="1" />
                <rect x="15.5" y="6" width="3" height="12" rx="1" />
                <rect x="18.5" y="8" width="3" height="8" rx="1" />
                <path d="M8.5 12h7" />
            </svg>
        </template>
        <div class="flex flex-col gap-2">
            <div class="flex items-center justify-between gap-2">
                <p class="text-sm text-secondary-text">{{ entry.dates_label }}</p>
                <StatusPill v-if="entry.phase" :text="entry.phase.text" :tone="entry.phase.tone" />
            </div>
            <p v-if="!entry.sets.length" class="text-sm text-secondary-text">No sets logged</p>
            <template v-else>
                <h4 class="text-sm font-semibold text-secondary-text">{{ entry.sets_title }}</h4>
                <dl class="flex flex-col gap-2">
                    <div v-for="row in entry.sets" :key="row.label" class="flex justify-between gap-2">
                        <dt>{{ row.label }}</dt>
                        <dd class="tabular-nums" :class="toneText[row.tone]">{{ row.value }}</dd>
                    </div>
                </dl>
            </template>
        </div>
    </ItemCard>
</template>
