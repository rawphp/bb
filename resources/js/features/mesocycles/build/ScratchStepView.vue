<script setup lang="ts">
import LabelStylePicker from './LabelStylePicker.vue';
import type { Build, LabelStyle } from './types';

// Build from scratch: how many sessions, and how to label them (MESO-005). Ported from
// rp-clone's ScratchStepView.
defineProps<{ build: Build; disabled?: boolean }>();

const emit = defineEmits<{ days: [days: number]; labelStyle: [style: LabelStyle] }>();

function chooseDays(event: Event): void {
    emit('days', Number((event.target as HTMLSelectElement).value));
}
</script>

<template>
    <div class="space-y-4">
        <label class="flex min-h-tap items-center gap-3">
            <span class="grow text-primary-text">Sessions per week</span>
            <select aria-label="Sessions per week" class="rounded-lg bg-transparent py-2 text-accent disabled:opacity-50" :disabled="disabled" @change="chooseDays">
                <option v-for="option in build.days_per_week_options" :key="option.value" :value="option.value" :selected="option.value === build.days_per_week">{{ option.text }}</option>
            </select>
        </label>
        <section class="space-y-1.5">
            <h3 class="text-sm text-secondary-text">Day labels</h3>
            <LabelStylePicker :build="build" :disabled="disabled" @change="emit('labelStyle', $event)" />
        </section>
    </div>
</template>
