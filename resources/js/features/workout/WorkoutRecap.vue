<script setup lang="ts">
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import StatusPill from '@/design/StatusPill.vue';

// WO-011: the recap a finished workout opens on. An accent band with the session's time,
// sets and weight lifted, then each exercise's best set with a pill saying how it moved on
// the same session last week. The server words and tones every line; this only draws them.
defineProps<{ recap: NonNullable<WorkoutShowOutput['recap']> }>();
</script>

<template>
    <section class="overflow-hidden rounded-2xl bg-surface" aria-labelledby="workout-recap-title" data-test="recap">
        <div class="flex flex-col gap-3 bg-accent px-4 pt-4 pb-3.5 text-on-accent">
            <h2 id="workout-recap-title" class="flex items-center gap-2 text-xl font-bold">
                <svg viewBox="0 0 24 24" class="size-7 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9.25" />
                    <path d="m7.75 12.25 2.75 2.75 5.75-6" />
                </svg>
                {{ recap.title }}
            </h2>
            <dl class="flex flex-wrap gap-x-7 gap-y-2">
                <div v-for="stat in recap.stats" :key="stat.label" class="flex flex-col-reverse" data-test="recap-stat">
                    <dt class="text-xs font-semibold tracking-wide uppercase">{{ stat.label }}</dt>
                    <dd class="text-2xl leading-tight font-bold whitespace-nowrap tabular-nums">{{ stat.value }}</dd>
                </div>
            </dl>
        </div>
        <ul class="flex flex-col divide-y divide-secondary-text/15 px-4">
            <li v-for="line in recap.exercises" :key="line.workout_exercise_id" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5" data-test="recap-exercise">
                <div class="flex min-w-0 flex-1 flex-col">
                    <span class="font-semibold text-primary-text">{{ line.name }}</span>
                    <span class="text-sm text-secondary-text tabular-nums">{{ line.best_text }}</span>
                </div>
                <StatusPill v-if="line.change" :text="line.change.text" :tone="line.change.tone" data-test="recap-change" />
            </li>
        </ul>
    </section>
</template>
