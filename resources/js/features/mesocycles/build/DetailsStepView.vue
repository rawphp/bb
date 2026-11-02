<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import WarningLabel from '../WarningLabel.vue';
import LabelStylePicker from './LabelStylePicker.vue';
import type { Build, LabelStyle } from './types';

// The last step of every route: name, length and start (MESO-004), day labels (MESO-005)
// and what the phase sets (MESO-003). Ported from rp-clone's DetailsStepView. The name
// saves when the field is left and after a typing pause. It is the user's own text, so it
// is not turned off while a write runs: that would drop the focus mid-word.
const props = defineProps<{ build: Build; name: string; disabled?: boolean }>();

const emit = defineEmits<{ edit: [name: string]; save: [name: string]; weeks: [weeks: number]; start: [date: string]; labelStyle: [style: LabelStyle] }>();

const TYPING_PAUSE_MS = 400;
const text = ref(props.name);
let pause: ReturnType<typeof setTimeout> | undefined;

function typed(event: Event): void {
    text.value = (event.target as HTMLInputElement).value;
    emit('edit', text.value);
    clearTimeout(pause);
    pause = setTimeout(() => emit('save', text.value), TYPING_PAUSE_MS);
}

function left(): void {
    clearTimeout(pause);
    emit('edit', text.value);
    emit('save', text.value);
}

onBeforeUnmount(() => clearTimeout(pause));

function chooseWeeks(event: Event): void {
    emit('weeks', Number((event.target as HTMLSelectElement).value));
}

function chooseStart(event: Event): void {
    const date = (event.target as HTMLInputElement).value;
    if (date !== '') emit('start', date);
}
</script>

<template>
    <div class="space-y-4">
        <section class="space-y-1">
            <label class="flex min-h-tap items-center gap-3">
                <span class="w-16 text-primary-text">Name</span>
                <input :value="text" type="text" aria-label="Name" autocapitalize="words" class="min-w-0 grow bg-transparent py-2 text-right" @input="typed" @change="left" />
            </label>
            <label class="flex min-h-tap items-center gap-3">
                <span class="grow text-primary-text">Length</span>
                <select aria-label="Weeks" class="rounded-lg bg-transparent py-2 text-accent disabled:opacity-50" :disabled="disabled" @change="chooseWeeks">
                    <option v-for="option in build.weeks_options" :key="option.value" :value="option.value" :selected="option.value === build.weeks">{{ option.text }}</option>
                </select>
            </label>
            <label class="flex min-h-tap items-center gap-3">
                <span class="grow text-primary-text">Start</span>
                <span class="text-sm text-secondary-text">{{ build.start_text }}</span>
                <input type="date" aria-label="Start" :value="build.start_date" :disabled="disabled" class="rounded-lg bg-transparent py-2 text-accent disabled:opacity-50" @change="chooseStart" />
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
                <div class="flex min-h-tap items-center justify-between gap-3">
                    <dt class="text-primary-text">Rep ranges</dt>
                    <dd class="text-right text-secondary-text">{{ build.rep_range_text ?? '–' }}</dd>
                </div>
            </dl>
        </section>
    </div>
</template>
