<script setup lang="ts">
import type { ProgressShowOutput } from '@/contracts/progress_show';
import ItemCard from '@/design/ItemCard.vue';
import { toneText } from '@/design/toneText';

// One past phase: dates, type, weight change and average rate, with its mesocycles (ANA-006, ANA-008).
defineProps<{ entry: ProgressShowOutput['phase_history'][number]; pending?: boolean }>();
</script>

<template>
    <ItemCard data-phase-history :title="entry.title" :pending="pending">
        <template #icon>
            <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round">
                <path d="M5 21V4M5 4h13v9H5" />
                <path d="M5 4h3.25v3H5zM11.5 4h3.25v3H11.5zM8.25 7h3.25v3H8.25zM14.75 7H18v3h-3.25zM5 10h3.25v3H5zM11.5 10h3.25v3H11.5z" fill="currentColor" stroke="none" />
            </svg>
        </template>
        <div class="flex flex-col gap-2">
            <p class="text-sm text-secondary-text">{{ entry.dates_label }}</p>
            <dl class="flex flex-col gap-2">
                <div v-for="line in entry.lines" :key="line.label" class="flex justify-between gap-2">
                    <dt>{{ line.label }}</dt>
                    <dd class="tabular-nums" :class="toneText[line.tone]">{{ line.value }}</dd>
                </div>
            </dl>
            <template v-if="entry.mesocycles.length">
                <hr class="border-secondary-text/25" />
                <h4 class="text-sm font-semibold text-secondary-text">Mesocycles</h4>
                <dl class="flex flex-col gap-2">
                    <div v-for="meso in entry.mesocycles" :key="meso.label" class="flex justify-between gap-2">
                        <dt>{{ meso.label }}</dt>
                        <dd class="tabular-nums" :class="toneText[meso.tone]">{{ meso.value }}</dd>
                    </div>
                </dl>
            </template>
        </div>
    </ItemCard>
</template>
