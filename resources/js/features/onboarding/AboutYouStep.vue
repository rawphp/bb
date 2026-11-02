<script setup lang="ts">
import { computed } from 'vue';
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import type { Answers } from './choices';
import NumberField from './parts/NumberField.vue';

type AboutYou = OnboardingShowOutput['about_you'];
type HeightUnit = AboutYou['height']['unit'];
type WeightUnit = AboutYou['weight']['unit'];

// Step 2: name, sex, date of birth, height and weight (ONB-004). Units come from the
// server and switch here; the server converts a number to the new unit. What is typed
// is held by OnboardingView (`drafts`) and goes with the next write; a pick (sex, a
// unit) is saved at once. Ported from rp-clone's AboutYouStep.
const props = defineProps<{ about: AboutYou; drafts: Answers; disabled?: boolean }>();

const emit = defineEmits<{ answer: [answers: Answers]; draft: [answers: Answers] }>();

const birthDate = computed(() => props.drafts.birth_date ?? props.about.birth_date.date ?? null);
// A typed height or weight is in the unit on screen; one in another unit is not this field's.
const height = computed(() => (props.drafts.height?.unit === props.about.height.unit ? props.drafts.height : props.about.height));
const weight = computed(() => (props.drafts.weight?.unit === props.about.weight.unit ? props.drafts.weight : props.about.weight));

function typedCm(cm: number | null): void {
    emit('draft', { height: { unit: 'cm', cm } });
}

// Whole feet, as rp-clone's field takes them; inches keep their decimals.
function typedFeet(feet: number | null): void {
    emit('draft', { height: { unit: 'feetInches', feet: feet === null ? null : Math.trunc(feet), inches: height.value.inches ?? null } });
}

function typedInches(inches: number | null): void {
    emit('draft', { height: { unit: 'feetInches', feet: height.value.feet ?? null, inches } });
}

function pickedBirthDate(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    // A cleared field keeps the saved date: the server needs a date or nothing.
    if (value) emit('draft', { birth_date: value });
}

const segment = 'flex min-h-9 flex-1 items-center justify-center rounded-lg px-3 text-sm font-medium disabled:opacity-50';
</script>

<template>
    <div class="flex flex-col gap-4">
        <label class="flex flex-col gap-1.5">
            <span class="text-sm font-semibold text-primary-text">Name</span>
            <input
                :value="drafts.name ?? about.name"
                type="text"
                autocomplete="given-name"
                autocapitalize="words"
                placeholder="Name"
                class="min-h-tap rounded-xl bg-background px-3.5 text-primary-text outline-none placeholder:text-secondary-text/60"
                @input="emit('draft', { name: ($event.target as HTMLInputElement).value })"
            />
        </label>

        <div class="flex flex-col gap-1.5">
            <span class="text-sm font-semibold text-primary-text">Sex</span>
            <div role="radiogroup" aria-label="Sex" class="flex gap-1 rounded-xl bg-background p-1">
                <button
                    v-for="option in about.sex_options"
                    :key="option.value"
                    type="button"
                    role="radio"
                    :aria-checked="option.is_selected"
                    :disabled="disabled || !option.is_enabled"
                    :class="[segment, option.is_selected ? 'bg-surface text-primary-text shadow-sm' : 'text-primary-text']"
                    @click="option.is_selected || emit('answer', { sex: option.value as 'male' | 'female' })"
                >
                    {{ option.title }}
                </button>
            </div>
        </div>

        <div class="flex flex-col gap-1.5">
            <span class="text-sm font-semibold text-primary-text">Date of birth</span>
            <button
                v-if="birthDate === null"
                type="button"
                data-test="set-birth-date"
                class="min-h-tap self-start rounded-xl bg-accent/15 px-4 font-medium text-accent active:opacity-70 disabled:opacity-50"
                :disabled="about.birth_date.is_locked"
                @click="emit('draft', { birth_date: about.birth_date.initial })"
            >
                Set date of birth
            </button>
            <input
                v-else
                type="date"
                aria-label="Date of birth"
                :value="birthDate"
                :min="about.birth_date.min ?? undefined"
                :max="about.birth_date.max ?? undefined"
                :disabled="about.birth_date.is_locked"
                class="min-h-tap self-start rounded-xl bg-background px-3.5 text-primary-text disabled:opacity-50"
                @change="pickedBirthDate"
            />
        </div>

        <div class="flex flex-col gap-1.5">
            <span class="text-sm font-semibold text-primary-text">Height</span>
            <div role="radiogroup" aria-label="Height unit" class="flex gap-1 rounded-xl bg-background p-1">
                <button
                    v-for="option in about.height.unit_options"
                    :key="option.value"
                    type="button"
                    role="radio"
                    :aria-checked="option.is_selected"
                    :disabled="disabled || !option.is_enabled"
                    :class="[segment, option.is_selected ? 'bg-surface text-primary-text shadow-sm' : 'text-primary-text']"
                    @click="option.is_selected || emit('answer', { height: { unit: option.value as HeightUnit } })"
                >
                    {{ option.title }}
                </button>
            </div>
            <NumberField v-if="about.height.unit === 'cm'" label="Height" unit="cm" :model-value="height.cm" @update:model-value="typedCm" />
            <div v-else class="flex gap-2">
                <NumberField class="flex-1" label="Feet" unit="ft" :model-value="height.feet" @update:model-value="typedFeet" />
                <NumberField class="flex-1" label="Inches" unit="in" :model-value="height.inches" @update:model-value="typedInches" />
            </div>
        </div>

        <div class="flex flex-col gap-1.5">
            <span class="text-sm font-semibold text-primary-text">Weight</span>
            <div role="radiogroup" aria-label="Weight unit" class="flex gap-1 rounded-xl bg-background p-1">
                <button
                    v-for="option in about.weight.unit_options"
                    :key="option.value"
                    type="button"
                    role="radio"
                    :aria-checked="option.is_selected"
                    :disabled="disabled || !option.is_enabled"
                    :class="[segment, option.is_selected ? 'bg-surface text-primary-text shadow-sm' : 'text-primary-text']"
                    @click="option.is_selected || emit('answer', { weight: { unit: option.value as WeightUnit } })"
                >
                    {{ option.title }}
                </button>
            </div>
            <NumberField
                label="Weight"
                :unit="about.weight.unit"
                :model-value="weight.value"
                @update:model-value="(value) => emit('draft', { weight: { unit: about.weight.unit, value } })"
            />
        </div>
    </div>
</template>
