<script setup lang="ts">
import DayCell from './DayCell.vue';
import { TONE_CLASS, type MesocycleWeek } from './types';

// One week of the mesocycle (MESO-040, MESO-041): the Now and Diet break pills, the
// calorie target, sets per muscle and the days. While a write is waiting to sync,
// the server's numbers are dimmed (OFF-008).
withDefaults(defineProps<{ week: MesocycleWeek; mesocycleId: string; pending?: boolean }>(), { pending: false });
</script>

<template>
    <section
        class="flex w-[190px] shrink-0 snap-center flex-col gap-2.5 self-start rounded-[14px] bg-surface p-3"
        :class="{ 'outline-2 -outline-offset-2 outline-accent': week.now }"
        :aria-label="week.title"
        :data-now="week.now ? 'true' : undefined"
    >
        <div class="flex flex-col gap-1">
            <h3 class="flex items-center gap-2 text-lg font-semibold text-primary-text">
                {{ week.title }}
                <span v-if="week.now" class="rounded-full bg-accent px-1.5 py-0.5 text-xs font-bold text-on-accent">{{ week.now.text }}</span>
            </h3>
            <span v-if="week.diet_break" class="self-start rounded-full px-2.5 py-0.5 text-sm font-medium" :class="TONE_CLASS[week.diet_break.tone]">
                {{ week.diet_break.text }}
            </span>
            <p class="text-sm text-secondary-text" :class="{ 'opacity-50': pending }">{{ week.calories_text ?? 'No calorie target yet' }}</p>
            <p v-if="pending" class="text-sm text-secondary-text">Waiting to sync</p>
        </div>
        <div v-if="week.muscle_sets.length" class="flex flex-col gap-0.5 text-sm">
            <h4 class="text-xs font-semibold text-secondary-text">Sets per muscle</h4>
            <p v-for="entry in week.muscle_sets" :key="entry.muscle_title" class="flex justify-between gap-1">
                <span class="text-primary-text">{{ entry.muscle_title }}</span>
                <span class="font-semibold tabular-nums text-primary-text" :class="{ 'opacity-50': pending }">{{ entry.sets }}</span>
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
