<script setup lang="ts">
import type { TrainShowOutput } from '@/contracts/train_show';
import MesocycleView from './MesocycleView.vue';
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';

// Train → Current mesocycle: the mesocycle view of the one that is running (MESO-040).
// Ported from rp-clone's CurrentMesocycleView.
const props = defineProps<{ payload?: TrainShowOutput }>();

const { data, failure, load } = useRead<TrainShowOutput>('train_show', {}, 'Your mesocycles could not be loaded.', props.payload);
</script>

<template>
    <MesocycleView v-if="data?.current" :key="data.current.id" :mesocycle-id="data.current.id" />
    <main v-else class="bb-screen-content">
        <h1 class="mb-4 text-center text-base font-semibold">Current mesocycle</h1>
        <div v-if="data" class="flex flex-col items-center gap-2 pt-24 text-center">
            <p class="text-secondary-text">No mesocycle is running.</p>
            <RouterLink to="/train/mesocycles" class="flex min-h-tap items-center text-accent">All mesocycles</RouterLink>
        </div>
        <ReadState v-else :failure="failure" what="Your mesocycles" @retry="load" />
    </main>
</template>
