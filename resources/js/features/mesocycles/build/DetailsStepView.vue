<script setup lang="ts">
import WarningLabel from '../WarningLabel.vue';
import LabelStylePicker from './LabelStylePicker.vue';
import type { Build, LabelStyle } from './types';

// The last step of every route: name, length and start (MESO-004), day labels (MESO-005)
// and what the phase sets (MESO-003). Ported from rp-clone's DetailsStepView. The name is
// the user's own text: it only reports what is typed, and the flow sends it with Create or
// the next change. The field is not turned off while a write runs, which would drop the
// focus mid-word. The pickers show what the server last said, so a refused save leaves
// nothing picked that was never saved.
const props = defineProps<{ build: Build; name: string; disabled?: boolean }>();

const emit = defineEmits<{ edit: [name: string]; weeks: [weeks: number]; start: [date: string]; labelStyle: [style: LabelStyle] }>();

function chooseWeeks(event: Event): void {
    const select = event.target as HTMLSelectElement;
    const picked = Number(select.value);
    select.value = String(props.build.weeks);
    if (picked !== props.build.weeks) emit('weeks', picked);
}

function chooseStart(event: Event): void {
    const input = event.target as HTMLInputElement;
    const date = input.value;
    input.value = props.build.start_date;
    if (date !== '' && date !== props.build.start_date) emit('start', date);
}
</script>

<template>
    <div class="space-y-4">
        <section class="space-y-1">
            <label class="flex min-h-tap items-center gap-3">
                <span class="w-16 text-primary-text">Name</span>
                <input :value="name" type="text" aria-label="Name" autocapitalize="words" class="min-w-0 grow bg-transparent py-2 text-right" @input="emit('edit', ($event.target as HTMLInputElement).value)" />
            </label>
            <label class="flex min-h-tap items-center gap-3">
                <span class="grow text-primary-text">Length</span>
                <select aria-label="Weeks" class="rounded-lg bg-transparent py-2 text-accent disabled:opacity-50" :disabled="disabled" @change="chooseWeeks">
                    <option v-for="option in build.weeks_options" :key="option.value" :value="option.value" :selected="option.value === build.weeks">{{ option.text }}</option>
                </select>
            </label>
            <label class="flex min-h-tap items-center gap-3">
                <span class="grow text-primary-text">Start</span>
                <!-- The server's words for the start show; the native date picker sits over them, unseen. -->
                <span class="relative rounded-lg py-2 text-accent" :class="{ 'opacity-50': disabled }">
                    {{ build.start_text }}
                    <input type="date" aria-label="Start" :value="build.start_date" :disabled="disabled" class="absolute inset-0 size-full opacity-0" @change="chooseStart" />
                </span>
            </label>
            <div class="space-y-1.5 pt-1 text-sm text-secondary-text">
                <p>The last week is the deload. A mesocycle starts on the first day of a plan week.</p>
                <WarningLabel v-if="build.phase_warning" :text="build.phase_warning.text" />
            </div>
        </section>

        <section class="space-y-1.5">
            <h3 class="text-sm text-secondary-text">Day labels</h3>
            <LabelStylePicker :build="build" :disabled="disabled" @change="emit('labelStyle', $event)" />
        </section>

        <section>
            <h3 class="pb-1.5 text-sm text-secondary-text">From the phase</h3>
            <dl class="divide-y divide-secondary-text/15">
                <div class="flex min-h-tap items-center justify-between gap-3">
                    <dt class="text-primary-text">Phase</dt>
                    <dd class="text-secondary-text">{{ build.phase_title ?? 'None on this date' }}</dd>
                </div>
                <div class="flex min-h-tap flex-col justify-center gap-0.5 py-2">
                    <dt class="text-primary-text">Rep ranges</dt>
                    <dd class="text-secondary-text">{{ build.rep_range_text ?? '–' }}</dd>
                </div>
            </dl>
        </section>
    </div>
</template>
