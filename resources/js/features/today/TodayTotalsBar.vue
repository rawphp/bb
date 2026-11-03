<script setup lang="ts">
import type { TodayShowOutput } from '@/contracts/today_show';
import ProgressBar from '@/design/ProgressBar.vue';
import StatusPill from '@/design/StatusPill.vue';
import WarningLine from './sheets/WarningLine.vue';

// SCH-006: calories and protein eaten against the day's target, the day type, and the
// buttons that re-fit the day: Redistribute (DAY-002), Save food macros (DAY-001) and
// Adjust (DAY-020). SAFE-018's warnings sit under the bars. Ported from rp-clone's TodayTotalsBar.
withDefaults(defineProps<{ totals: NonNullable<TodayShowOutput['totals']>; pending?: boolean }>(), { pending: false });

const emit = defineEmits<{ redistribute: []; saveFood: []; adjust: [] }>();

const iconButton = 'flex min-h-tap min-w-tap items-center justify-center rounded-lg bg-accent/15 text-accent disabled:bg-background disabled:text-secondary-text';
</script>

<template>
    <section data-test="totals" class="flex flex-col gap-2.5 rounded-2xl bg-surface p-3.5" aria-labelledby="today-totals">
        <div class="flex items-center gap-2">
            <h2 id="today-totals" class="flex-1 text-base font-semibold text-primary-text">Day totals</h2>
            <button type="button" :class="iconButton" aria-label="Redistribute" :disabled="!totals.can_redistribute" @click="emit('redistribute')">
                <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true">
                    <path d="M4 20 15 9M14 4v3M12.5 5.5h3M19 9v3M17.5 10.5h3M19 3v1.5M18.25 3.75h1.5" />
                </svg>
            </button>
            <button type="button" :class="iconButton" aria-label="Save food macros" @click="emit('saveFood')">
                <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M12 5v14M5 12h14" />
                </svg>
            </button>
            <button type="button" class="min-h-tap rounded-lg bg-accent/15 px-3 text-accent" data-test="adjust" @click="emit('adjust')">Adjust</button>
        </div>
        <div class="flex flex-wrap gap-2">
            <StatusPill :text="totals.day_type.text" :tone="totals.day_type.tone" />
            <StatusPill :text="totals.status.text" :tone="totals.status.tone" :pending="pending" />
        </div>
        <ProgressBar
            title="Calories"
            :label="totals.calories.label"
            :value-text="totals.calories.value_text"
            :fraction="totals.calories.fraction"
            :tone="totals.calories.tone"
            tint="calories"
            :pending="pending"
        />
        <ProgressBar
            title="Protein"
            :label="totals.protein.label"
            :value-text="totals.protein.value_text"
            :fraction="totals.protein.fraction"
            :tone="totals.protein.tone"
            tint="protein"
            :pending="pending"
        />
        <WarningLine v-for="warning in totals.warnings" :key="warning.text" :text="warning.text" />
    </section>
</template>
