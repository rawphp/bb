<script setup lang="ts">
import { computed } from 'vue';
import type { TrainShowOutput } from '@/contracts/train_show';
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import StatusPill from '@/design/StatusPill.vue';

// The Train tab (NAV-001): the plan on top, then the current mesocycle as one card (MESO-008) with its week track
// and today's session (NAV-002), then Mesocycles, Templates and Exercises. Ported from rp-clone's TrainView. The plan
// timeline itself is the plan screens' (#106); this links to it. With no mesocycle running, Current mesocycle is a
// plain link like the others.
const props = defineProps<{ payload?: TrainShowOutput }>();

const { data, failure, load } = useRead<TrainShowOutput>('train_show', {}, "Today's session could not be loaded.", props.payload);

type WeekState = 'past' | 'current' | 'upcoming' | 'skipped';

// Colour is not the only signal (UI-030): this week is taller and its label bold, a skipped week is an outline, and
// the track's label reads the week out.
const MARK: Record<WeekState, string> = {
    past: 'h-2 bg-accent',
    current: 'h-3.5 bg-accent ring-4 ring-accent/20',
    upcoming: 'h-2 bg-secondary-text/20',
    skipped: 'h-2 border border-dashed border-secondary-text/50',
};

// SF Symbols in the Swift list: calendar.day.timeline.left, calendar, doc.on.doc, figure.strengthtraining.traditional.
const CURRENT = { to: '/train/current', title: 'Current mesocycle', icon: 'M4 5h16M4 12h10M4 19h6M18 10v8' } as const;
const LINKS = [
    { to: '/train/mesocycles', title: 'Mesocycles', icon: 'M5 6h14v13H5zM5 10h14M9 4v4M15 4v4' },
    { to: '/train/templates', title: 'Templates', icon: 'M8 7h10v13H8zM6 17V4h10' },
    { to: '/exercises', title: 'Exercises', icon: 'M3 12h18M6 8v8M9 9v6M15 9v6M18 8v8' },
] as const;

const current = computed(() => data.value?.current ?? null);
const session = computed(() => data.value?.todays_session ?? null);
const links = computed(() => (current.value ? LINKS : [CURRENT, ...LINKS]));
</script>

<template>
    <main class="bb-screen-content">
        <h1 class="mb-4 px-1 text-3xl font-bold">Train</h1>

        <section class="mb-6">
            <h2 class="px-4 pb-1.5 text-sm font-semibold text-secondary-text">Plan</h2>
            <RouterLink to="/plan" class="flex min-h-tap items-center gap-2 rounded-2xl bg-surface px-4 py-3 text-primary-text active:opacity-70" data-test="plan">
                <span class="flex-1">Your plan</span>
                <svg viewBox="0 0 24 24" class="size-4 text-secondary-text" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="m9 5 7 7-7 7" />
                </svg>
            </RouterLink>
        </section>

        <section v-if="current" class="mb-6">
            <h2 class="px-4 pb-1.5 text-sm font-semibold text-secondary-text">Current mesocycle</h2>
            <div class="overflow-hidden rounded-2xl bg-surface" data-test="current-mesocycle">
                <RouterLink to="/train/current" class="block px-4 pt-4 pb-3 text-primary-text active:opacity-70">
                    <div class="flex items-start gap-2">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-accent">{{ current.name }}</p>
                            <p class="text-2xl leading-tight font-bold">{{ current.week_text }}</p>
                            <p class="mt-0.5 text-sm text-secondary-text">{{ current.phase_title }} · {{ current.dates_text }}</p>
                        </div>
                        <svg viewBox="0 0 24 24" class="mt-1 size-4 shrink-0 text-secondary-text" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="m9 5 7 7-7 7" />
                        </svg>
                    </div>
                    <div role="img" :aria-label="current.week_text" class="mt-4 flex h-4 items-center gap-1.5" data-test="week-track">
                        <span
                            v-for="mark in current.week_track"
                            :key="mark.number"
                            :data-state="mark.state"
                            class="flex-1 rounded-full"
                            :class="MARK[mark.state]"
                        />
                    </div>
                    <div aria-hidden="true" class="mt-1 flex gap-1.5 text-xs text-secondary-text" data-test="week-labels">
                        <span
                            v-for="mark in current.week_track"
                            :key="mark.number"
                            class="flex-1 text-center whitespace-nowrap"
                            :class="mark.state === 'current' && 'font-bold text-accent'"
                        >{{ mark.deload ? 'Deload' : mark.number }}</span>
                    </div>
                </RouterLink>

                <RouterLink
                    v-if="session"
                    :to="`/workouts/${session.mesocycle_id}/${session.week}/${session.day}`"
                    class="flex min-h-tap items-center gap-3 border-t border-secondary-text/15 px-4 py-3 text-primary-text active:opacity-70"
                    :aria-label="`Open today's session, ${session.title}`"
                    data-test="todays-session"
                >
                    <svg viewBox="0 0 24 24" class="size-6 shrink-0 text-accent" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true">
                        <path d="M3 12h18M6 8v8M9 9v6M15 9v6M18 8v8" />
                    </svg>
                    <span class="min-w-0 flex-1">
                        <span class="block text-xs font-semibold tracking-wide text-secondary-text uppercase">Today</span>
                        <span class="block truncate font-semibold">{{ session.title }}</span>
                    </span>
                    <span class="flex min-h-tap items-center rounded-full bg-accent px-6 font-semibold text-on-accent">Open</span>
                </RouterLink>

                <div v-if="current.next_mesocycle" class="border-t border-secondary-text/15 px-4 py-3 text-sm" data-test="next-mesocycle">
                    <p>
                        <span class="font-semibold">Next mesocycle</span>
                        <span class="text-secondary-text"> · {{ current.next_mesocycle.starts_text }}</span>
                    </p>
                    <StatusPill
                        v-if="current.next_mesocycle.active_rest"
                        class="mt-2"
                        :text="current.next_mesocycle.active_rest.text"
                        :tone="current.next_mesocycle.active_rest.tone"
                    />
                </div>
            </div>
        </section>
        <section v-else-if="!data" class="mb-6">
            <ReadState :failure="failure" what="Today's session" @retry="load" />
        </section>

        <nav class="divide-y divide-secondary-text/15 overflow-hidden rounded-2xl bg-surface" aria-label="Train">
            <RouterLink v-for="link in links" :key="link.to" :to="link.to" class="flex min-h-tap items-center gap-3 px-4 text-primary-text active:opacity-70">
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
