<script setup lang="ts">
import DayCell from './DayCell.vue';
import StatusPill from '@/design/StatusPill.vue';
import PendingMark from '@/design/PendingMark.vue';
import { PENDING_DIM, type MesocycleWeek } from './types';

// One week of the mesocycle (MESO-040, MESO-041): the Now and Diet break pills, the
// calorie target, sets per muscle and the days. While a write is waiting to sync,
// the server's numbers are dimmed (OFF-008).
withDefaults(defineProps<{ week: MesocycleWeek; mesocycleId: string; pending?: boolean }>(), { pending: false });
</script>

<template>
    <section
        class="flex w-48 shrink-0 snap-center flex-col gap-2.5 self-start rounded-2xl bg-surface p-3"
        :class="{ 'outline-2 -outline-offset-2 outline-accent': week.now }"
        :aria-label="week.title"
        :data-now="week.now ? 'true' : undefined"
    >
        <div class="flex flex-col gap-1">
            <h3 class="flex items-center gap-2 text-lg font-semibold text-primary-text">
                {{ week.title }}
                <StatusPill v-if="week.now" :text="week.now.text" :tone="week.now.tone" />
            </h3>
            <StatusPill v-if="week.diet_break" class="self-start" :text="week.diet_break.text" :tone="week.diet_break.tone" />
            <p class="text-sm text-secondary-text">
                <PendingMark v-if="pending" derived>{{ week.calories_text ?? 'No calorie target yet' }}</PendingMark>
                <template v-else>{{ week.calories_text ?? 'No calorie target yet' }}</template>
            </p>
        </div>
        <div v-if="week.muscle_sets.length" class="flex flex-col gap-0.5 text-sm">
            <h4 class="text-xs font-semibold text-secondary-text">Sets per muscle</h4>
            <p v-for="entry in week.muscle_sets" :key="entry.muscle_title" class="flex justify-between gap-1">
                <span class="text-primary-text">{{ entry.muscle_title }}</span>
                <span class="font-semibold tabular-nums text-primary-text" :class="{ [PENDING_DIM]: pending }">{{ entry.sets }}</span>
            </p>
        </div>
        <DayCell
            v-for="day in week.days"
            :key="day.day"
            :day="day"
            :pending="pending"
            :to="`/workouts/${mesocycleId}/${week.number}/${day.day}`"
        />
    </section>
</template>
