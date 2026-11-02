<script setup lang="ts">
import type { Build } from './types';

// Build from scratch: how many sessions (MESO-005). Ported from rp-clone's ScratchStepView.
// The day labels are chosen on the details step, once there are days to label. The picker
// shows what the server last said, so a refused save leaves nothing picked that was never saved.
const props = defineProps<{ build: Build; disabled?: boolean }>();

const emit = defineEmits<{ days: [days: number] }>();

function chooseDays(event: Event): void {
    const select = event.target as HTMLSelectElement;
    const picked = Number(select.value);
    select.value = String(props.build.days_per_week);
    if (picked !== props.build.days_per_week) emit('days', picked);
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
    </div>
</template>
