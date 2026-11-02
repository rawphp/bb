<script setup lang="ts">
import { ref } from 'vue';
import { write } from '@/app/call';
import { failureMessage } from '@/app/errors';
import type { MesocycleShowOutput } from '@/contracts/mesocycle_show';
import type { PrioritySetInput } from '@/contracts/priority_set';
import PriorityRowView from './PriorityRowView.vue';
import ReadState from '@/app/ReadState.vue';
import type { Priority, PriorityRow } from './types';
import { useRead } from '@/app/useRead';

// Progression rates: change a muscle's priority on a saved mesocycle, from the next
// session (MESO-032), or set it Off (MESO-034). Ported from rp-clone's ProgressionRatesView.
const props = defineProps<{ payload?: MesocycleShowOutput; mesocycleId?: string }>();

const { data, failure, load } = useRead<MesocycleShowOutput>('mesocycle_show', props.payload, () => ({
    mesocycle_id: props.mesocycleId ?? props.payload?.id,
}));

const saving = ref(false);
const saveError = ref<string | null>(null);

async function setPriority(row: PriorityRow, priority: Priority): Promise<void> {
    if (!data.value) return;
    saving.value = true;
    saveError.value = null;
    try {
        await write('priority_set', { mesocycle_id: data.value.id, muscle: row.muscle, priority } satisfies PrioritySetInput);
        await load();
    } catch (error) {
        saveError.value = failureMessage(error, 'The priority could not be saved.');
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <main class="bb-screen-content">
        <h1 class="mb-4 text-center text-base font-semibold">Progression rates</h1>

        <template v-if="data?.saved">
            <h2 class="px-4 pb-1.5 text-sm text-secondary-text">Muscle priority</h2>
            <div class="divide-y divide-secondary-text/15 rounded-2xl bg-surface px-4" :aria-busy="saving || undefined">
                <PriorityRowView
                    v-for="row in data.saved.priorities"
                    :key="row.muscle"
                    :muscle-title="row.muscle_title"
                    :priority="row.priority"
                    :priority-title="row.priority_title"
                    :bars="row.bars"
                    :options="data.priority_options"
                    :disabled="saving"
                    @change="(priority) => setPriority(row, priority)"
                />
            </div>
            <div class="space-y-1.5 px-4 pt-1.5 text-sm text-secondary-text">
                <p v-if="saveError" role="alert" class="text-tone-warning">{{ saveError }}</p>
                <p>A change applies from your next session.</p>
            </div>
        </template>
        <p v-else-if="data" class="rounded-2xl bg-surface p-4 text-secondary-text">This mesocycle is still being built.</p>
        <ReadState v-else :failure="failure" what="The mesocycle" @retry="load" />
    </main>
</template>
