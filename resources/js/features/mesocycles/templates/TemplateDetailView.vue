<script setup lang="ts">
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import type { TemplatesShowOutput } from '@/contracts/templates_show';
import PriorityBarsView from '../PriorityBarsView.vue';

// One template (MESO-012, MESO-015): its days with a slot per muscle, the muscle
// priorities and the weekly sets per muscle. Ported from rp-clone's TemplateDetailView.
// The build flow's Next ("Use this template") is what uses it, so there is no button here;
// `embedded` is that flow showing it, and drops the page header. Templates are library
// data no write changes, so nothing here waits to sync (no OFF-008 state).
const props = withDefaults(defineProps<{ payload?: TemplatesShowOutput; templateId?: string; embedded?: boolean }>(), {
    payload: undefined,
    templateId: undefined,
    embedded: false,
});

const { data, failure, load } = useRead<TemplatesShowOutput>(
    'templates_show',
    { template_id: props.templateId ?? props.payload?.detail?.id },
    'The template could not be loaded.',
    props.payload,
);
</script>

<template>
    <component :is="embedded ? 'div' : 'main'" :class="{ 'bb-screen-content': !embedded }">
        <template v-if="data">
            <header v-if="!embedded" class="mb-4 flex items-center gap-2">
                <RouterLink to="/train/templates" class="flex min-h-tap items-center text-accent">Templates</RouterLink>
                <h1 v-if="data.detail" class="grow text-center text-base font-semibold">{{ data.detail.name }}</h1>
            </header>

            <p v-if="!data.detail" class="rounded-2xl bg-surface p-4 text-secondary-text">This template could not be found.</p>
            <div v-else class="space-y-4">
                <p class="px-4 text-secondary-text">{{ data.detail.summary }}</p>

                <section v-for="day in data.detail.days" :key="day.label" :aria-label="day.label" data-test="day">
                    <h2 class="px-4 pb-1.5 text-sm text-secondary-text">{{ day.label }}</h2>
                    <ul class="divide-y divide-secondary-text/15 rounded-2xl bg-surface px-4">
                        <li v-for="(slot, index) in day.slots" :key="index" data-test="slot" class="flex min-h-tap items-center gap-2 py-1.5">
                            <span class="flex min-w-0 grow flex-col">
                                <span class="text-primary-text">{{ slot.exercise_name }}</span>
                                <span class="text-sm text-secondary-text">{{ slot.muscle_title }}</span>
                            </span>
                            <span class="text-sm tabular-nums text-secondary-text">{{ slot.sets_text }}</span>
                        </li>
                    </ul>
                </section>

                <section v-if="data.detail.priorities.length > 0" aria-label="Muscle priorities">
                    <h2 class="px-4 pb-1.5 text-sm text-secondary-text">Muscle priorities</h2>
                    <ul class="divide-y divide-secondary-text/15 rounded-2xl bg-surface px-4">
                        <li v-for="row in data.detail.priorities" :key="row.muscle" data-test="priority" class="flex min-h-tap items-center gap-3">
                            <span class="grow text-primary-text">{{ row.muscle_title }}</span>
                            <PriorityBarsView :bars="row.bars" />
                            <span class="min-w-18 text-right text-sm text-secondary-text">{{ row.priority_title }}</span>
                        </li>
                    </ul>
                </section>

                <section v-if="data.detail.weekly_sets.length > 0" aria-label="Weekly sets per muscle">
                    <h2 class="px-4 pb-1.5 text-sm text-secondary-text">Weekly sets per muscle</h2>
                    <ul class="divide-y divide-secondary-text/15 rounded-2xl bg-surface px-4">
                        <li v-for="row in data.detail.weekly_sets" :key="row.muscle_title" data-test="weekly" class="flex min-h-tap items-center gap-3">
                            <span class="grow text-primary-text">{{ row.muscle_title }}</span>
                            <span class="tabular-nums text-secondary-text">{{ row.sets_text }}</span>
                        </li>
                    </ul>
                </section>
            </div>
        </template>
        <ReadState v-else :failure="failure" what="The template" @retry="load" />
    </component>
</template>
