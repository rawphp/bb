<script setup lang="ts">
import type { TrainShowOutput } from '@/contracts/train_show';
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';

// The Train tab (NAV-001): the plan on top, today's session when there is one (NAV-002),
// then the current mesocycle, Mesocycles, Templates and Exercises. Ported from rp-clone's
// TrainView. The plan timeline itself is the plan screens' (#106); this links to it.
const props = defineProps<{ payload?: TrainShowOutput }>();

const { data, failure, load } = useRead<TrainShowOutput>('train_show', props.payload);

// SF Symbols in the Swift list: calendar.day.timeline.left, calendar, doc.on.doc, figure.strengthtraining.traditional.
const LINKS = [
    { to: '/train/current', title: 'Current mesocycle', icon: 'M4 5h16M4 12h10M4 19h6M18 10v8' },
    { to: '/train/mesocycles', title: 'Mesocycles', icon: 'M5 6h14v13H5zM5 10h14M9 4v4M15 4v4' },
    { to: '/train/templates', title: 'Templates', icon: 'M8 7h10v13H8zM6 17V4h10' },
    { to: '/exercises', title: 'Exercises', icon: 'M3 12h18M6 8v8M9 9v6M15 9v6M18 8v8' },
] as const;
</script>

<template>
    <main class="bb-screen-content">
        <h1 class="mb-4 px-1 text-3xl font-bold">Train</h1>

        <section class="mb-6">
            <h2 class="px-4 pb-1.5 text-sm font-semibold text-secondary-text">Plan</h2>
            <RouterLink to="/plan" class="flex min-h-tap items-center gap-2 rounded-2xl bg-surface px-4 py-3 text-primary-text" data-test="plan">
                <span class="flex-1">Your plan</span>
                <svg viewBox="0 0 24 24" class="size-4 text-secondary-text" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="m9 5 7 7-7 7" />
                </svg>
            </RouterLink>
        </section>

        <section v-if="data?.todays_session" class="mb-6">
            <h2 class="px-4 pb-1.5 text-sm font-semibold text-secondary-text">Today's session</h2>
            <RouterLink
                :to="`/workouts/${data.todays_session.mesocycle_id}/${data.todays_session.week}/${data.todays_session.day}`"
                class="flex min-h-tap items-center gap-2 rounded-2xl bg-surface px-4 py-3"
                :aria-label="`Open today's session, ${data.todays_session.title}`"
                data-test="todays-session"
            >
                <span class="flex-1 text-primary-text">{{ data.todays_session.title }}</span>
                <span class="font-semibold text-accent">Open</span>
            </RouterLink>
        </section>
        <section v-else-if="!data" class="mb-6">
            <ReadState :failure="failure" what="Today's session" @retry="load" />
        </section>

        <nav class="divide-y divide-secondary-text/15 overflow-hidden rounded-2xl bg-surface" aria-label="Train">
            <RouterLink v-for="link in LINKS" :key="link.to" :to="link.to" class="flex min-h-tap items-center gap-3 px-4 text-primary-text">
                <svg viewBox="0 0 24 24" class="size-5 text-accent" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true">
                    <path :d="link.icon" />
                </svg>
                <span class="flex-1">{{ link.title }}</span>
                <svg viewBox="0 0 24 24" class="size-4 text-secondary-text" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="m9 5 7 7-7 7" />
                </svg>
            </RouterLink>
        </nav>
    </main>
</template>
