<script setup lang="ts">
import { ref } from 'vue';
import ReadState from '@/app/ReadState.vue';
import { ulid } from '@/app/ulid';
import { useRead } from '@/app/useRead';
import { useWrite } from '@/app/useWrite';
import type { PlanShowOutput } from '@/contracts/plan_show';
import type { PrebuiltPlanApplyInput } from '@/contracts/prebuilt_plan_apply';
import PendingMark from '@/design/PendingMark.vue';
import { toneClasses } from '@/design/tones';
import { useLeave } from './planScreen';

// Plan → 24-week plans (PLAN-030): RP's two prebuilt plans, laid out by the server
// from the end of the plan, to preview and add. `pending` dims the server's dates
// while a write waits to sync (OFF-008). Ported from rp-clone's PrebuiltPlansView.
const props = withDefaults(defineProps<{ payload?: PlanShowOutput; pending?: boolean }>(), { pending: false });

type Plan = PrebuiltPlanApplyInput['plan'];

const leave = useLeave();
const { data, failure, load } = useRead<PlanShowOutput>('plan_show', {}, 'Your plan could not be loaded.', props.payload);
const { send, busy, error } = useWrite(load);

// One create id per plan, made once, so a retry after no answer is the same create (CAP-019).
const firstPhaseIds: Record<Plan, string> = { muscleGain: ulid(), fatLoss: ulid() };
const adding = ref<Plan | null>(null);

async function apply(plan: Plan): Promise<void> {
    adding.value = plan;
    const input = { plan, first_phase_id: firstPhaseIds[plan] } satisfies PrebuiltPlanApplyInput;
    const outcome = await send('prebuilt_plan_apply', input, 'The plan could not be added.');
    adding.value = null;
    if (outcome === 'done') leave();
}

const weeks = (count: number) => `${count} ${count === 1 ? 'week' : 'weeks'}`;
</script>

<template>
    <main class="bb-screen-content space-y-6">
        <h1 class="text-center text-lg font-semibold">24-week plans</h1>

        <template v-if="data">
            <p class="px-1 text-sm text-secondary-text">
                Each plan lays out the phases, mesocycles and active rest from the end of your plan. Mesocycles are built when each one starts.
            </p>

            <section v-for="preview in data.prebuilt_plans" :key="preview.plan" class="space-y-2" :aria-labelledby="`prebuilt-${preview.plan}`" data-test="plan">
                <h2 :id="`prebuilt-${preview.plan}`" class="px-1 text-sm font-medium text-secondary-text">{{ preview.title }}</h2>
                <div class="space-y-3 rounded-xl bg-surface p-4">
                    <p class="text-sm text-secondary-text">
                        {{ preview.summary }} ·
                        <PendingMark v-if="pending" derived>{{ preview.starts_text }}</PendingMark>
                        <template v-else>{{ preview.starts_text }}</template>
                    </p>
                    <ul class="space-y-2" data-test="phases">
                        <li v-for="(phase, index) in preview.phases" :key="index">
                            <p>{{ phase.title }} · {{ phase.weeks_text }}</p>
                            <p class="text-sm text-secondary-text" :class="{ 'opacity-60': pending }">
                                {{ phase.dates_text }}<template v-if="phase.rate_text"> · {{ phase.rate_text }}</template>
                            </p>
                        </li>
                    </ul>
                    <ul class="space-y-1 text-sm text-secondary-text" data-test="segments">
                        <li v-for="segment in preview.segments" :key="segment.start_week" class="flex items-center gap-2" :data-kind="segment.kind">
                            <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.75">
                                <path v-if="segment.kind === 'active_rest'" d="M5 19c0-8 6-14 14-14 0 8-6 14-14 14zM5 19l7-7" />
                                <path v-else d="M3 10v4M6 8v8M18 8v8M21 10v4M6 12h12" />
                            </svg>
                            {{ segment.label }} · {{ weeks(segment.weeks) }}
                        </li>
                    </ul>
                    <p v-if="preview.refusal" class="rounded-xl p-3 text-sm" :class="toneClasses.warning" role="alert" data-test="refusal">{{ preview.refusal }}</p>
                    <button
                        v-else
                        type="button"
                        class="min-h-tap w-full rounded-xl bg-accent font-semibold text-on-accent active:opacity-70 disabled:opacity-50"
                        data-test="apply"
                        :disabled="busy"
                        :aria-busy="adding === preview.plan"
                        @click="apply(preview.plan)"
                    >
                        {{ adding === preview.plan ? 'Adding…' : 'Add to my plan' }}
                    </button>
                </div>
            </section>

            <p v-if="!data.prebuilt_plans.length" class="text-center text-secondary-text" data-test="none">No 24-week plan can be added to your plan right now.</p>
            <p v-if="error" class="rounded-2xl p-4" :class="toneClasses.warning" role="alert" data-test="error">{{ error }}</p>
        </template>

        <ReadState v-else :failure="failure" what="Your plan" @retry="load" />
    </main>
</template>
