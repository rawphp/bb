<script setup lang="ts">
import { computed, ref } from 'vue';
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import { useWrite } from '@/app/useWrite';
import type { PlanDietBreakSetInput } from '@/contracts/plan_diet_break_set';
import type { PlanShowOutput } from '@/contracts/plan_show';
import DeloadWeekSheet from './DeloadWeekSheet.vue';
import PlanTimelineView from './PlanTimelineView.vue';

// Train → Plan and phases: the timeline, then the plan's actions, add a phase and RP's
// 24-week plans (PLAN-006, PLAN-030; their screens are #107's). A deload week opens its
// calories here (PLAN-022, PLAN-023). Ported from rp-clone's PlanView, with PlanActions
// and PlanDestinations inline: the routes are the destinations.
const props = withDefaults(defineProps<{ payload?: PlanShowOutput; pending?: boolean }>(), { pending: false });

const { data, failure, load } = useRead<PlanShowOutput>('plan_show', {}, 'Your plan could not be loaded.', props.payload);
const { send, busy, error } = useWrite(load);

// The open deload week, by its plan week, so the sheet shows each new read of it.
const openWeek = ref<number | null>(null);
const deload = computed(() => data.value?.deloads.find((week) => week.week === openWeek.value) ?? null);

function openDeload(week: number): void {
    error.value = null;
    openWeek.value = week;
}

function setDietBreak(dietBreak: boolean): void {
    if (!deload.value) return;
    const input = { week_start: deload.value.start_date, diet_break: dietBreak } satisfies PlanDietBreakSetInput;
    void send('plan_diet_break_set', input, "This week's diet break could not be changed.");
}

const ACTIONS = [
    { to: '/plan/add', title: 'Add a phase', test: 'add-phase', icon: 'M12 7.5v9M7.5 12h9M21.5 12a9.5 9.5 0 1 1-19 0 9.5 9.5 0 0 1 19 0Z' },
    { to: '/plan/prebuilt', title: "RP's 24-week plans", test: 'prebuilt', icon: 'M3.5 6.5h15v12h-15zM3.5 10.5h15M7.5 4v4M14.5 4v4M18 15v6M15 18h6' },
] as const;
</script>

<template>
    <main class="bb-screen-content">
        <header class="mb-4 flex items-center gap-2">
            <span class="min-w-tap" aria-hidden="true" />
            <h1 class="flex-1 text-center text-base font-semibold">Plan and phases</h1>
            <span class="min-w-tap" aria-hidden="true" />
        </header>

        <template v-if="data">
            <section class="mb-6 overflow-hidden rounded-2xl bg-surface" aria-label="Plan timeline">
                <PlanTimelineView :plan="data" :pending="pending" @deload="openDeload" />
            </section>

            <nav class="divide-y divide-secondary-text/15 overflow-hidden rounded-2xl bg-surface" aria-label="Plan actions">
                <RouterLink v-for="action in ACTIONS" :key="action.to" :to="action.to" class="flex min-h-tap items-center gap-3 px-4 text-accent" :data-test="action.test">
                    <svg viewBox="0 0 24 24" class="size-6 shrink-0" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                        <path :d="action.icon" />
                    </svg>
                    <span class="flex-1 py-3">{{ action.title }}</span>
                </RouterLink>
            </nav>

            <DeloadWeekSheet
                v-if="deload"
                :deload="deload"
                :warning="data.diet_break_off_warning"
                :busy="busy"
                :error="error"
                @set="setDietBreak"
                @close="openWeek = null"
            />
        </template>
        <ReadState v-else :failure="failure" what="Your plan" @retry="load" />
    </main>
</template>
