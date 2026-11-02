<script setup lang="ts">
import { ref, watch } from 'vue';
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import type { Answers } from './choices';
import NumberField from './parts/NumberField.vue';

type AboutYou = OnboardingShowOutput['about_you'];
type HeightUnit = AboutYou['height']['unit'];
type WeightUnit = AboutYou['weight']['unit'];

// Step 2: name, sex, date of birth, height and weight (ONB-004). Units come from the
// server and switch here; the server converts a saved number to the new unit. A pick is
// saved at once, a typed field when it loses focus. A field cleared and left goes back to
// what is saved: the server keeps an answer it is sent no value for. Ported from
// rp-clone's AboutYouStep.
const props = defineProps<{ about: AboutYou }>();

const emit = defineEmits<{ answer: [answers: Answers] }>();

// The name as the field shows it. A re-read replaces it only while nobody is typing, as
// NumberField does, so a save landing mid-word does not wipe the word.
const name = ref(props.about.name);
const typingName = ref(false);
watch(
    () => props.about.name,
    (saved) => {
        if (!typingName.value) name.value = saved;
    },
);

function typedName(): void {
    if (name.value !== props.about.name) emit('answer', { name: name.value });
}

function pickedBirthDate(event: Event): void {
    const input = event.target as HTMLInputElement;
    if (input.value) emit('answer', { birth_date: input.value });
    else input.value = props.about.birth_date.date ?? '';
}

function typedCm(cm: number | null): void {
    if (cm !== null) emit('answer', { height: { unit: 'cm', cm } });
}

// Feet and inches as their fields show them. A height saves both halves, and the save of
// the first may still be out when the second is left, so the other half comes from its
// field, not from the last read. Each follows the server when the server's value changes.
const feet = ref(props.about.height.feet ?? null);
const inches = ref(props.about.height.inches ?? null);
watch(
    () => props.about.height.feet,
    (saved) => (feet.value = saved ?? null),
);
watch(
    () => props.about.height.inches,
    (saved) => (inches.value = saved ?? null),
);

// Whole feet, as rp-clone's field takes them; inches keep their decimals.
function typedFeet(typed: number | null): void {
    if (typed === null) return;
    feet.value = Math.trunc(typed);
    emit('answer', { height: { unit: 'feetInches', feet: feet.value, inches: inches.value } });
}

function typedInches(typed: number | null): void {
    if (typed === null) return;
    inches.value = typed;
    emit('answer', { height: { unit: 'feetInches', feet: feet.value, inches: inches.value } });
}

function typedWeight(value: number | null): void {
    if (value !== null) emit('answer', { weight: { unit: props.about.weight.unit, value } });
}

const segment = 'flex min-h-9 flex-1 items-center justify-center rounded-lg px-3 text-sm font-medium disabled:opacity-50';
</script>

<template>
    <div class="flex flex-col gap-4">
        <label class="flex flex-col gap-1.5">
            <span class="text-sm font-semibold text-primary-text">Name</span>
            <input
                v-model="name"
                type="text"
                maxlength="100"
                autocomplete="given-name"
                autocapitalize="words"
                placeholder="Name"
                class="min-h-tap rounded-xl bg-background px-3.5 text-primary-text outline-none placeholder:text-secondary-text/60"
                @focus="typingName = true"
                @blur="typingName = false"
                @change="typedName"
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
                    :disabled="!option.is_enabled"
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
                v-if="!about.birth_date.date"
                type="button"
                data-test="set-birth-date"
                class="min-h-tap self-start rounded-xl bg-accent/15 px-4 font-medium text-accent active:opacity-70 disabled:opacity-50"
                :disabled="about.birth_date.is_locked"
                @click="emit('answer', { birth_date: about.birth_date.initial })"
            >
                Set date of birth
            </button>
            <input
                v-else
                type="date"
                aria-label="Date of birth"
                :value="about.birth_date.date"
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
                    :disabled="!option.is_enabled"
                    :class="[segment, option.is_selected ? 'bg-surface text-primary-text shadow-sm' : 'text-primary-text']"
                    @click="option.is_selected || emit('answer', { height: { unit: option.value as HeightUnit } })"
                >
                    {{ option.title }}
                </button>
            </div>
            <NumberField v-if="about.height.unit === 'cm'" label="Height" unit="cm" :model-value="about.height.cm" :commits-while-typing="false" @update:model-value="typedCm" />
            <div v-else class="flex gap-2">
                <NumberField class="flex-1" label="Feet" unit="ft" :model-value="about.height.feet" :commits-while-typing="false" @update:model-value="typedFeet" />
                <NumberField class="flex-1" label="Inches" unit="in" :model-value="about.height.inches" :commits-while-typing="false" @update:model-value="typedInches" />
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
                    :disabled="!option.is_enabled"
                    :class="[segment, option.is_selected ? 'bg-surface text-primary-text shadow-sm' : 'text-primary-text']"
                    @click="option.is_selected || emit('answer', { weight: { unit: option.value as WeightUnit } })"
                >
                    {{ option.title }}
                </button>
            </div>
            <NumberField
                label="Weight"
                :unit="about.weight.unit"
                :model-value="about.weight.value"
                :commits-while-typing="false"
                @update:model-value="typedWeight"
            />
        </div>
    </div>
</template>
