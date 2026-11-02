<script setup lang="ts">
import PriorityRowView from '../PriorityRowView.vue';
import WarningLabel from '../WarningLabel.vue';
import { takePick } from './pick';
import type { Priority, PriorityOption } from '../types';
import type { Build } from './types';

// Meso Builder: days per week and a priority per muscle (MESO-020 to MESO-023), ported
// from rp-clone's BuilderStepView. The warnings are the server's and never block (MESO-021).
const props = defineProps<{ build: Build; priorityOptions: PriorityOption[]; disabled?: boolean }>();

const emit = defineEmits<{ days: [days: number]; priority: [muscle: Build['priorities'][number]['muscle'], priority: Priority] }>();

// The picker shows what the server last said, so a refused save leaves nothing picked that was never saved.
function chooseDays(event: Event): void {
    const picked = takePick(event, String(props.build.days_per_week));
    if (picked !== null) emit('days', Number(picked));
}
</script>

<template>
    <div class="space-y-4">
        <label class="flex min-h-tap items-center gap-3">
            <span class="grow text-primary-text">Days per week</span>
            <select aria-label="Days per week" class="rounded-lg bg-transparent py-2 text-accent disabled:opacity-50" :disabled="disabled" @change="chooseDays">
                <option v-for="option in build.days_per_week_options" :key="option.value" :value="option.value" :selected="option.value === build.days_per_week">{{ option.text }}</option>
            </select>
        </label>

        <section v-if="build.warnings.length > 0" data-test="warnings" class="space-y-1.5">
            <h3 class="text-sm text-secondary-text">Warnings</h3>
            <WarningLabel v-for="warning in build.warnings" :key="warning.text" :text="warning.text" />
        </section>

        <p v-if="build.priority_notice" class="text-sm text-secondary-text">{{ build.priority_notice.text }}</p>

        <section>
            <h3 class="pb-1.5 text-sm text-secondary-text">Muscle priorities</h3>
            <div class="divide-y divide-secondary-text/15">
                <PriorityRowView
                    v-for="row in build.priorities"
                    :key="row.muscle"
                    :muscle-title="row.muscle_title"
                    :priority="row.priority"
                    :priority-title="row.priority_title"
                    :bars="row.bars"
                    :options="priorityOptions"
                    :disabled="disabled"
                    @change="(priority) => emit('priority', row.muscle, priority)"
                />
            </div>
            <p v-if="build.phase_title" class="pt-1.5 text-sm text-secondary-text">Starting from the {{ build.phase_title }} defaults.</p>
        </section>
    </div>
</template>
