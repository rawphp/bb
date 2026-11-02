<script setup lang="ts">
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import type { PlanShowOutput } from '@/contracts/plan_show';
import PhaseDetailView from './PhaseDetailView.vue';

// More → Adjust phase length and End current phase (PH-006, PH-007): the running phase,
// with that action already open, or why there is none. plan_show says which phase runs.
// Ported from rp-clone's CurrentPhaseView.
const props = withDefaults(defineProps<{ payload?: PlanShowOutput; action?: 'adjust-length' | 'end' }>(), { action: 'adjust-length' });

const { data, failure, load } = useRead<PlanShowOutput>('plan_show', {}, 'Your plan could not be loaded.', props.payload);

const TITLES = { 'adjust-length': 'Adjust phase length', end: 'End current phase' } as const;
</script>

<template>
    <PhaseDetailView v-if="data?.current" :key="data.current.phase_id" :phase-id="data.current.phase_id" :opening="action" />
    <main v-else class="bb-screen-content">
        <header class="mb-4 flex items-center gap-2">
            <span class="min-w-tap" aria-hidden="true" />
            <h1 class="flex-1 text-center text-base font-semibold">{{ TITLES[action] }}</h1>
            <span class="min-w-tap" aria-hidden="true" />
        </header>
        <p v-if="data" class="px-4 py-10 text-center text-secondary-text">No phase is running.</p>
        <ReadState v-else :failure="failure" what="Your plan" @retry="load" />
    </main>
</template>
