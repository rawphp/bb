<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import type { ProgressShowOutput } from '@/contracts/progress_show';
import OfflineNotice from '@/design/OfflineNotice.vue';
import PendingMark from '@/design/PendingMark.vue';
import Skeleton from '@/design/Skeleton.vue';
import MesocycleHistoryCard from './MesocycleHistoryCard.vue';
import PhaseHistoryCard from './PhaseHistoryCard.vue';
import ProgressChartView from './ProgressChartView.vue';
import ProgressPlaceholderView from './ProgressPlaceholderView.vue';
import ProgressShareCard from './ProgressShareCard.vue';
import ProgressSummaryView from './ProgressSummaryView.vue';
import ReviewCard from './ReviewCard.vue';
import StepsCard from './StepsCard.vue';
import ThisWeekCard from './ThisWeekCard.vue';
import { useSharePicture } from './useSharePicture';

// The Progress tab (ANA-001 to ANA-011): the weight chart, this week, the review
// card, steps and history, as progress_show sends them. Display state only.
const props = defineProps<{
    payload?: ProgressShowOutput;
    error?: string | null;
    /** No cached copy and no network (OFF-003). */
    offline?: boolean;
    /** An outbox entry that affects progress_show is waiting (OFF-008). */
    pending?: boolean;
    /** The picked scope did not load; the progress shown is still the other scope's. */
    scopeError?: string | null;
}>();

const emit = defineEmits<{ scope: [scope: ProgressShowOutput['scopes'][number]['value']] }>();

const router = useRouter();

// The weigh-in sheet and the schedule editor are built by their own issues.
const placeholders = {
    weighIn: { title: 'Log a weigh-in', message: 'Logging a weigh-in arrives with the weigh-ins screens.' },
    schedule: { title: 'Edit schedule', message: 'Editing the schedule arrives with the schedule menu.' },
} as const;
type Placeholder = keyof typeof placeholders;
const placeholder = ref<Placeholder | null>(null);
// Keeps the sheet's words while it slides away.
const shown = ref<Placeholder>('schedule');

function openPlaceholder(which: Placeholder): void {
    shown.value = which;
    placeholder.value = which;
}

const shareCard = ref<{ $el: SVGSVGElement } | null>(null);
// The card mounts off screen only while it is drawn into the picture (ANA-010, PWA-034).
const { sharing, drawing, shareError, share } = useSharePicture(
    () => shareCard.value?.$el,
    () => [props.payload?.share, props.payload?.chart] as const,
);

function pickScope(scope: ProgressShowOutput['scopes'][number]): void {
    if (!scope.selected) emit('scope', scope.value);
}
</script>

<template>
    <main class="bb-screen-content flex flex-col gap-4">
        <header class="flex min-h-tap items-center justify-between gap-2">
            <h1 class="text-3xl font-bold">{{ payload?.title ?? 'Progress' }}</h1>
            <button
                v-if="payload?.share && payload.chart"
                data-share
                type="button"
                aria-label="Share progress"
                :disabled="sharing"
                class="flex min-h-tap min-w-tap items-center justify-center rounded-full bg-surface text-primary-text"
                @click="share(payload.share)"
            >
                <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 3v12M8 7l4-4 4 4M8 10H6.5A1.5 1.5 0 0 0 5 11.5v8A1.5 1.5 0 0 0 6.5 21h11a1.5 1.5 0 0 0 1.5-1.5v-8A1.5 1.5 0 0 0 17.5 10H16" />
                </svg>
            </button>
        </header>
        <p v-if="shareError" role="alert" class="text-sm text-tone-warning">{{ shareError }}</p>

        <OfflineNotice v-if="offline" needs="Progress needs a connection." />
        <p v-else-if="error" role="alert" class="rounded-2xl bg-surface p-4 text-tone-warning">{{ error }}</p>

        <template v-else-if="!payload">
            <Skeleton shape="card" :count="3" label="Loading progress" />
        </template>

        <template v-else>
            <section v-if="payload.empty" class="flex flex-col items-center gap-3.5 rounded-2xl bg-surface p-5 text-center">
                <svg viewBox="0 0 24 24" class="size-9 text-accent" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 3v18h18M7 15l4-4 3 3 6-6M15 8h5v5" />
                </svg>
                <p class="text-lg font-semibold">{{ payload.empty.message }}</p>
                <button data-log-weigh-in type="button" class="min-h-tap rounded-xl bg-accent px-4 text-on-accent" @click="openPlaceholder('weighIn')">
                    {{ payload.empty.action_title }}
                </button>
            </section>

            <section v-else data-chart-card class="flex flex-col gap-3.5 rounded-2xl bg-surface p-3.5">
                <div role="group" aria-label="Chart range" class="flex rounded-lg bg-background p-0.5">
                    <button
                        v-for="scope in payload.scopes"
                        :key="scope.value"
                        data-scope
                        type="button"
                        :aria-pressed="scope.selected"
                        class="min-h-tap flex-1 rounded-md text-sm"
                        :class="scope.selected ? 'bg-surface font-semibold shadow-sm' : 'text-primary-text'"
                        @click="pickScope(scope)"
                    >
                        {{ scope.title }}
                    </button>
                </div>
                <p v-if="scopeError" role="alert" class="text-sm text-tone-warning">{{ scopeError }}</p>
                <div :class="{ 'opacity-50': pending }">
                    <ProgressChartView v-if="payload.chart" :chart="payload.chart" />
                    <p v-else class="flex min-h-30 items-center justify-center text-secondary-text">{{ payload.scope_message }}</p>
                </div>
                <ProgressSummaryView v-if="payload.summary.length" :summary="payload.summary" :pending="pending" />
                <PendingMark v-if="pending" derived />
            </section>

            <ThisWeekCard v-if="payload.this_week" :week="payload.this_week" :pending="pending" @edit-schedule="openPlaceholder('schedule')" />
            <ReviewCard v-if="payload.review_card" :card="payload.review_card" @open="router.push('/review')" />
            <StepsCard v-if="payload.steps" :steps="payload.steps" :pending="pending" />

            <section class="flex flex-col gap-3" aria-labelledby="progress-history">
                <h2 id="progress-history" class="text-xl font-bold">History</h2>

                <h3 class="pt-1 text-sm font-semibold text-secondary-text">Phases</h3>
                <p v-if="!payload.phase_history.length" class="text-sm text-secondary-text">No finished phases yet.</p>
                <PhaseHistoryCard v-for="entry in payload.phase_history" :key="entry.id" :entry="entry" :pending="pending" />

                <h3 class="pt-1 text-sm font-semibold text-secondary-text">Mesocycles</h3>
                <p v-if="!payload.mesocycle_history.length" class="text-sm text-secondary-text">No finished mesocycles yet.</p>
                <MesocycleHistoryCard v-for="entry in payload.mesocycle_history" :key="entry.id" :entry="entry" :pending="pending" />

                <RouterLink to="/progress/exercises" class="flex min-h-tap items-center justify-between rounded-2xl bg-surface p-3.5">
                    <span class="flex items-center gap-2.5">
                        <svg viewBox="0 0 24 24" class="size-6 text-accent" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true">
                            <rect x="3.5" y="4" width="17" height="16" rx="2.5" />
                            <path d="M8 9h8M8 12.5h8M8 16h5" />
                        </svg>
                        Exercise history
                    </span>
                    <svg viewBox="0 0 24 24" class="size-4 text-secondary-text" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                        <path d="M9 5l7 7-7 7" />
                    </svg>
                </RouterLink>
            </section>

            <div v-if="drawing && payload.share && payload.chart" aria-hidden="true" class="pointer-events-none fixed top-0 -left-[9999px]">
                <ProgressShareCard ref="shareCard" :content="payload.share" :chart="payload.chart" :summary="payload.summary" />
            </div>
        </template>

        <ProgressPlaceholderView
            :open="placeholder !== null"
            :title="placeholders[shown].title"
            :message="placeholders[shown].message"
            @close="placeholder = null"
        />
    </main>
</template>
