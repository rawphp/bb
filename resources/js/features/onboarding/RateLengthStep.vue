<script setup lang="ts">
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import ChoiceList from '@/design/ChoiceList.vue';
import PendingMark from '@/design/PendingMark.vue';
import { picked, toChoices, type Answers } from './choices';
import NumberField from './parts/NumberField.vue';
import StepNote from './parts/StepNote.vue';

type Section = OnboardingShowOutput['rate_length'];
type DateKey = 'start_date' | 'last_day';

// Step 6: rate and length (ONB-008, ONB-009). Each change is one answer: a preset, the
// custom rate, the length, the start, the last day or the target; the server works out
// the others and sends them all back, with the length limits and recommended range
// (TBL-010) and any warnings as the step's notices. While a change waits to sync, every
// number the server worked out (the rates, the length, the dates, the target) is dimmed
// and the length and custom rate carry the mark (OFF-008). Ported from rp-clone's RateLengthStep.
const props = defineProps<{ rateLength: Section; locked?: boolean; pending?: boolean }>();

const emit = defineEmits<{ answer: [answers: Answers] }>();

function slid(event: Event): number {
    return Number((event.target as HTMLInputElement).value);
}

// A date cleared and left sends nothing and shows the saved one again.
function pickedDate(key: DateKey, event: Event): void {
    const input = event.target as HTMLInputElement;
    const saved = props.rateLength[key].date ?? '';
    if (!input.value) input.value = saved;
    else if (input.value !== saved) emit('answer', { [key]: input.value });
}

function typedTarget(value: number | null): void {
    if (value !== null) emit('answer', { target_weight: { unit: props.rateLength.target_weight.unit, value } });
}

const heading = 'text-base font-semibold text-primary-text';
const slider = 'min-h-tap w-full accent-accent disabled:opacity-50';
const dateRow = 'flex min-h-tap items-center justify-between gap-3';
const dateInput = 'rounded-lg bg-background px-3 py-1.5 tabular-nums text-primary-text disabled:opacity-50';
</script>

<template>
    <div class="flex flex-col gap-5">
        <section class="flex flex-col gap-2">
            <h3 :class="heading">Rate</h3>
            <ChoiceList
                label="Rate"
                :class="{ 'opacity-60': pending }"
                :choices="toChoices<string>(rateLength.options)"
                :model-value="picked<string>(rateLength.options)"
                :disabled="locked"
                @update:model-value="(value) => emit('answer', { rate_option: value })"
            />
            <template v-if="rateLength.is_custom">
                <PendingMark v-if="pending" derived class="text-sm">{{ rateLength.custom_rate.text }}</PendingMark>
                <span v-else class="text-sm tabular-nums text-primary-text">{{ rateLength.custom_rate.text }}</span>
                <input
                    type="range"
                    aria-label="Custom rate"
                    :aria-valuetext="rateLength.custom_rate.text"
                    :min="rateLength.custom_rate.min"
                    :max="rateLength.custom_rate.max"
                    :step="rateLength.custom_rate.step"
                    :value="rateLength.custom_rate.value"
                    :disabled="locked"
                    :class="slider"
                    @change="emit('answer', { custom_rate: slid($event) })"
                />
            </template>
        </section>

        <section class="flex flex-col gap-1">
            <h3 :class="heading">Length</h3>
            <PendingMark v-if="pending" derived class="text-sm">{{ rateLength.weeks.text }}</PendingMark>
            <span v-else class="text-sm tabular-nums text-primary-text">{{ rateLength.weeks.text }}</span>
            <input
                type="range"
                aria-label="Length in weeks"
                :aria-valuetext="rateLength.weeks.text"
                :min="rateLength.weeks.min"
                :max="rateLength.weeks.max"
                :step="rateLength.weeks.step"
                :value="rateLength.weeks.value"
                :disabled="locked"
                :class="slider"
                @change="emit('answer', { weeks: slid($event) })"
            />
            <StepNote v-if="rateLength.recommended_text" :text="rateLength.recommended_text" />
        </section>

        <section class="flex flex-col gap-1" :class="{ 'opacity-60': pending }">
            <div v-if="rateLength.start_date.is_locked" :class="dateRow">
                <span class="text-primary-text">Start date</span>
                <span class="tabular-nums text-primary-text">{{ rateLength.start_date.text }}</span>
            </div>
            <label v-else :class="dateRow">
                <span class="text-primary-text">Start date</span>
                <input
                    type="date"
                    :value="rateLength.start_date.date"
                    :min="rateLength.start_date.min ?? undefined"
                    :max="rateLength.start_date.max ?? undefined"
                    :readonly="locked"
                    :class="dateInput"
                    @change="pickedDate('start_date', $event)"
                />
            </label>
            <label :class="dateRow">
                <span class="text-primary-text">Last day</span>
                <input
                    type="date"
                    :value="rateLength.last_day.date"
                    :min="rateLength.last_day.min ?? undefined"
                    :max="rateLength.last_day.max ?? undefined"
                    :disabled="rateLength.last_day.is_locked"
                    :readonly="locked"
                    :class="dateInput"
                    @change="pickedDate('last_day', $event)"
                />
            </label>
        </section>

        <section class="flex flex-col gap-1.5">
            <h3 :class="heading">Target weight</h3>
            <NumberField
                :class="{ 'opacity-60': pending }"
                label="Target weight"
                :unit="rateLength.target_weight.unit"
                :model-value="rateLength.target_weight.value"
                :commits-while-typing="false"
                :disabled="!rateLength.target_weight.can_edit"
                :readonly="locked"
                @update:model-value="typedTarget"
            />
            <StepNote v-if="rateLength.target_weight.note" :text="rateLength.target_weight.note" />
        </section>
    </div>
</template>
