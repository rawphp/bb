<script setup lang="ts">
import { onMounted, ref } from 'vue';
import type { PlanShowOutput } from '@/contracts/plan_show';
import PendingMark from '@/design/PendingMark.vue';
import { toneClasses } from '@/design/tones';
import { FLAG, phaseTints } from './phaseTints';

// The plan timeline (PLAN-003): phases on top, mesocycles under them, deload weeks marked
// and today marked, on the plan's week grid. A phase opens its detail (PLAN-004), a
// mesocycle opens the mesocycle (PLAN-005), and a deload week asks the plan to open its
// calories (PLAN-022, PLAN-023). Later phases are outlines (PLAN-006). Ported from
// rp-clone's PlanTimelineView. The server places every band in weeks; the view only
// turns weeks into pixels. `pending` dims the bands and marks the countdown (OFF-008).
const props = withDefaults(defineProps<{ plan: PlanShowOutput; pending?: boolean }>(), { pending: false });

const emit = defineEmits<{ deload: [week: number] }>();

const WEEK = 30;
// The deload marks, drawn in a filled circle: rp-clone's pause.circle.fill and
// arrow.down.right.circle.fill. The legend uses the same marks.
const MARKS = { deload: 'M10 8v8M14 8v8', deficit_deload: 'm8.5 8.5 7 7M15.5 10v5.5H10' } as const;
const px = (weeks: number, less = 0): string => `${weeks * WEEK - less}px`;

const scroller = ref<HTMLElement>();

// The timeline starts at week 1, so the running phase's title shows. When today is past
// the visible weeks, it scrolls today in, two weeks from the leading edge, as the Swift
// timeline does.
onMounted(() => {
    const position = props.plan.today_position;
    const view = scroller.value;
    if (position == null || !view || position * WEEK < view.clientWidth - WEEK) return;
    view.scrollLeft = Math.max(0, Math.floor(position) - 2) * WEEK;
});
</script>

<template>
    <div v-if="!plan.phases.length" class="p-4 text-sm text-secondary-text">Your plan will appear here once setup is done.</div>
    <div v-else class="space-y-3 py-3.5">
        <p v-if="plan.event_countdown" class="flex items-center gap-2 px-4 text-sm font-semibold text-tone-info" data-test="countdown">
            <svg viewBox="0 0 24 24" class="size-4 shrink-0" fill="currentColor" aria-hidden="true">
                <path :d="FLAG" />
            </svg>
            <PendingMark v-if="pending" derived>{{ plan.event_countdown }}</PendingMark>
            <template v-else>{{ plan.event_countdown }}</template>
        </p>
        <PendingMark v-else-if="pending" class="px-4" />

        <div ref="scroller" class="overflow-x-auto px-2" style="scrollbar-width: none">
            <div
                class="relative flex flex-col gap-1.5"
                :class="{ 'opacity-60': pending }"
                :style="{ width: px(plan.week_count, -16) }"
                data-test="timeline"
            >
                <div class="relative h-[18px]" aria-hidden="true" data-test="ruler">
                    <template v-for="week in plan.week_count" :key="week">
                        <span v-if="(week - 1) % 4 === 0" class="absolute text-[11px] text-secondary-text tabular-nums" :style="{ left: px(week - 1, -2) }">{{ week }}</span>
                    </template>
                </div>

                <div class="relative h-12">
                    <RouterLink
                        v-for="band in plan.phases"
                        :key="band.id"
                        :to="`/plan/phases/${band.id}`"
                        class="absolute top-0 flex h-12 flex-col justify-center overflow-hidden rounded-lg border-[1.5px] px-1.5"
                        :class="[
                            phaseTints[band.type].border,
                            band.state === 'outline' ? [phaseTints[band.type].text, 'border-dashed'] : [phaseTints[band.type].fill, 'text-on-accent'],
                            { 'opacity-45': band.state === 'past' },
                        ]"
                        :style="{ left: px(band.start_week), width: px(band.weeks, 2) }"
                        :aria-label="`${band.title}, ${band.weeks} weeks, ${band.rate_text ?? 'no rate'}${band.state === 'outline' ? ', outline' : ''}`"
                        :data-state="band.state"
                        data-test="phase"
                    >
                        <span class="truncate text-[13px] font-semibold">{{ band.title }}</span>
                        <span v-if="band.rate_text" class="truncate text-[11px]">{{ band.rate_text }}</span>
                    </RouterLink>
                </div>

                <div class="relative h-9">
                    <RouterLink
                        v-for="band in plan.mesocycles"
                        :key="band.id"
                        :to="`/train/mesocycles/${band.id}`"
                        class="absolute top-0 flex h-9 items-center truncate rounded-md px-1.5 text-xs font-semibold text-primary-text"
                        :class="band.is_current ? 'bg-accent/35' : 'bg-accent/15'"
                        :style="{ left: px(band.start_week), width: px(band.weeks, 2) }"
                        :aria-label="`${band.name}, ${band.weeks} weeks${band.is_current ? ', current' : ''}`"
                        :aria-current="band.is_current || undefined"
                        data-test="mesocycle"
                    >
                        {{ band.name }}
                    </RouterLink>
                </div>

                <div class="relative h-8">
                    <button
                        v-for="deload in plan.deloads"
                        :key="deload.week"
                        type="button"
                        class="absolute top-0 flex h-8 items-center justify-center"
                        :class="deload.marker === 'deficit_deload' ? 'text-tone-warning' : 'text-secondary-text'"
                        :style="{ left: px(deload.week), width: px(1) }"
                        :aria-label="`${deload.label}, ${deload.dates_text}`"
                        :data-marker="deload.marker"
                        data-test="deload"
                        @click="emit('deload', deload.week)"
                    >
                        <svg viewBox="0 0 24 24" class="size-5" aria-hidden="true">
                            <circle cx="12" cy="12" r="10" fill="currentColor" />
                            <path :d="MARKS[deload.marker]" fill="none" stroke="var(--color-surface)" stroke-width="2" stroke-linecap="round" />
                        </svg>
                    </button>
                </div>

                <span
                    v-if="plan.today_position != null"
                    class="absolute inset-y-0 w-0.5 bg-primary-text"
                    :style="{ left: `${(plan.today_position * WEEK).toFixed(1)}px` }"
                    role="img"
                    aria-label="Today"
                    data-test="today"
                />
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-x-3.5 gap-y-1 px-4 text-xs text-secondary-text" aria-hidden="true">
            <span class="flex items-center gap-1"><svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 18 18 6" /></svg>Today</span>
            <span v-for="(title, marker) in { deload: 'Deload', deficit_deload: 'Deficit deload' }" :key="marker" class="flex items-center gap-1">
                <svg viewBox="0 0 24 24" class="size-4"><circle cx="12" cy="12" r="10" fill="currentColor" /><path :d="MARKS[marker]" fill="none" stroke="var(--color-surface)" stroke-width="2" stroke-linecap="round" /></svg>
                {{ title }}
            </span>
        </div>

        <RouterLink
            v-if="plan.maintenance_suggestion"
:to="{ path: '/train/plan/add-phase', query: { type: 'maintenance', weeks: String(plan.maintenance_suggestion.weeks) } }"
            class="mx-4 flex min-h-tap items-center gap-2 rounded-[10px] p-2.5 text-[13px] font-semibold"
            :class="toneClasses.info"
            data-test="suggestion"
        >
            <svg viewBox="0 0 24 24" class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <path d="M9 18h6M10 21h4M12 3a6 6 0 0 0-3.5 10.9V16h7v-2.1A6 6 0 0 0 12 3Z" />
            </svg>
            {{ plan.maintenance_suggestion.text }}
        </RouterLink>
    </div>
</template>
