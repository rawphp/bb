<script setup lang="ts">
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import type { Answers } from './choices';
import StepNote from './parts/StepNote.vue';
import StepPanel from './parts/StepPanel.vue';
import Stepper from './parts/Stepper.vue';

type Section = OnboardingShowOutput['steps'];
type Group = Section['groups'][number];
type Range = { min: number; max: number };

// Step 10: a typical daily step range for each weekday (ONB-013), set for weekdays and
// weekends at once, from a preset or the steppers, or for each day. The answer is all
// seven days, built from what the step shows with the changed days replaced; the server
// checks the range and warns (SAFE-015) in the step's notices. A group whose days
// differ shows no steppers until a preset makes them the same. Ported from rp-clone's
// LifestyleSteps.
const props = defineProps<{ steps: Section; locked?: boolean }>();

const emit = defineEmits<{ answer: [answers: Answers] }>();

const count = new Intl.NumberFormat('en-US');

function send(weekdays: number[], range: Range): void {
    emit('answer', {
        step_ranges: props.steps.days.map((day) => (weekdays.includes(day.weekday) ? { weekday: day.weekday, ...range } : { weekday: day.weekday, min: day.min, max: day.max })),
    });
}

function groupDays(group: Group): number[] {
    return group.weekdays as number[];
}

function pickedPreset(group: Group, event: Event): void {
    const select = event.target as HTMLSelectElement;
    const preset = props.steps.presets[Number(select.value)];
    select.value = '';
    if (preset) send(groupDays(group), { min: preset.min, max: preset.max });
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <StepNote :text="steps.note" />
        <StepPanel v-for="group in steps.groups" :key="group.key">
            <div class="flex min-h-tap items-center justify-between gap-3">
                <span class="text-sm font-semibold text-primary-text">{{ group.title }}</span>
                <select
                    :aria-label="`${group.title} presets`"
                    :disabled="locked"
                    class="min-h-tap rounded-lg bg-transparent px-2 font-medium text-accent disabled:opacity-50"
                    @change="pickedPreset(group, $event)"
                >
                    <option value="" selected disabled>Presets</option>
                    <option v-for="(preset, index) in steps.presets" :key="preset.title" :value="index">{{ preset.title }}</option>
                </select>
            </div>
            <template v-if="!group.is_mixed">
                <span class="tabular-nums text-primary-text">{{ group.text }}</span>
                <Stepper
                    :label="`${group.title} fewest`"
                    :text="`Fewest: ${count.format(group.min)}`"
                    :model-value="group.min"
                    :min="0"
                    :max="steps.max_steps"
                    :step="steps.increment"
                    :disabled="locked"
                    @update:model-value="(min) => send(groupDays(group), { min, max: group.max })"
                />
                <Stepper
                    :label="`${group.title} most`"
                    :text="`Most: ${count.format(group.max)}`"
                    :model-value="group.max"
                    :min="0"
                    :max="steps.max_steps"
                    :step="steps.increment"
                    :disabled="locked"
                    @update:model-value="(max) => send(groupDays(group), { min: group.min, max })"
                />
            </template>
            <StepNote v-else text="These days have different ranges. Pick a preset to make them the same." />
        </StepPanel>
        <details class="group">
            <summary class="flex min-h-tap cursor-pointer list-none items-center justify-between font-medium text-primary-text">
                Set each day
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5 text-accent transition-transform group-open:rotate-90" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 6l6 6-6 6" />
                </svg>
            </summary>
            <div class="flex flex-col gap-2 pt-2">
                <StepPanel v-for="day in steps.days" :key="day.weekday">
                    <span class="text-sm font-semibold text-primary-text">{{ day.full_name }}</span>
                    <Stepper
                        :label="`${day.full_name} fewest`"
                        :text="`Fewest: ${count.format(day.min)}`"
                        :model-value="day.min"
                        :min="0"
                        :max="steps.max_steps"
                        :step="steps.increment"
                        :disabled="locked"
                        @update:model-value="(min) => send([day.weekday], { min, max: day.max })"
                    />
                    <Stepper
                        :label="`${day.full_name} most`"
                        :text="`Most: ${count.format(day.max)}`"
                        :model-value="day.max"
                        :min="0"
                        :max="steps.max_steps"
                        :step="steps.increment"
                        :disabled="locked"
                        @update:model-value="(max) => send([day.weekday], { min: day.min, max })"
                    />
                </StepPanel>
            </div>
        </details>
    </div>
</template>
