<script setup lang="ts">
import { computed } from 'vue';
import { isPending } from '@/app/outbox';
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import PendingMark from '@/design/PendingMark.vue';

// An earlier session of the mesocycle, read-only (WO-008): each exercise with the sets
// that were logged, as weight × reps. Ported from rp-clone's LookBackDetail.
const props = defineProps<{ mesocycleId: string; week: number; day: number; payload?: WorkoutShowOutput }>();

const { data, failure, load } = useRead<WorkoutShowOutput>(
    'workout_show',
    { mesocycle_id: props.mesocycleId, week: props.week, day: props.day },
    'That session could not be loaded.',
    props.payload,
);
// OFF-008: a change waiting to sync may move what these numbers say.
const pending = computed(() => isPending('workout_show'));

type WorkoutSet = WorkoutShowOutput['exercises'][number]['sets'][number];

/** The set as weight × reps; whichever the user did not log is left out. */
const loadText = (set: WorkoutSet) => [set.weight?.text, set.reps].filter((part) => part !== null && part !== undefined).join(' × ');
</script>

<template>
    <div data-test="look-back-detail" class="flex flex-col gap-4 p-4">
        <ReadState v-if="!data" :failure="failure" what="That session" @retry="load().catch(() => undefined)" />
        <section v-for="exercise in data?.exercises" v-else :key="exercise.id" class="flex flex-col" data-test="exercise">
            <h3 class="font-semibold text-primary-text">{{ exercise.name }}</h3>
            <p v-if="!exercise.sets.some((set) => set.is_logged)" class="min-h-tap py-2 text-secondary-text" data-test="nothing">Nothing logged</p>
            <template v-for="set in exercise.sets" :key="set.id">
                <div v-if="set.is_logged" class="flex min-h-tap items-center justify-between gap-2" data-test="set">
                    <span class="text-secondary-text" data-test="number">Set {{ set.number }}</span>
                    <PendingMark v-if="pending" derived class="tabular-nums text-primary-text" data-test="load">{{ loadText(set) }}</PendingMark>
                    <span v-else class="tabular-nums text-primary-text" data-test="load">{{ loadText(set) }}</span>
                </div>
            </template>
        </section>
    </div>
</template>
