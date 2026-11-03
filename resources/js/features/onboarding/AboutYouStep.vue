<script setup lang="ts">
import { ref, watch } from 'vue';
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import SegmentedPicker from '@/design/SegmentedPicker.vue';
import type { Answers } from './choices';
import NumberField from './parts/NumberField.vue';

type AboutYou = OnboardingShowOutput['about_you'];
type HeightUnit = AboutYou['height']['unit'];
type WeightUnit = AboutYou['weight']['unit'];

// Step 2: name, sex, date of birth, height and weight (ONB-004). Units come from the
// server and switch here; the server converts a saved number to the new unit. A pick is
// saved at once, a typed field when it loses focus. A number or date cleared and left
// goes back to what is saved, since a missing value keeps the saved answer; a cleared
// name is sent as typed, for the server to judge. Ported from rp-clone's AboutYouStep.
const props = defineProps<{ about: AboutYou; locked?: boolean }>();

const emit = defineEmits<{ answer: [answers: Answers] }>();

// The name as the field shows it. A re-read replaces it only while nobody is typing, as
// NumberField does, so a background refresh landing mid-word does not wipe the word.
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

// A height saves both halves; the other half is the saved one, which is what its field
// shows, since nothing is typed while a save is out. An empty half is 0, as in rp-clone's
// field. Whole feet, as rp-clone's field takes them; inches keep their decimals.
function typedFeet(feet: number | null): void {
    if (feet !== null) emit('answer', { height: { unit: 'feetInches', feet: Math.trunc(feet), inches: props.about.height.inches ?? 0 } });
}

function typedInches(inches: number | null): void {
    if (inches !== null) emit('answer', { height: { unit: 'feetInches', feet: props.about.height.feet ?? 0, inches } });
}

function typedWeight(value: number | null): void {
    if (value !== null) emit('answer', { weight: { unit: props.about.weight.unit, value } });
}

type Option = AboutYou['sex_options'][number];

// The server's options as SegmentedPicker takes them, and the one it holds as chosen.
function segments(options: Option[]): { value: string; label: string; disabled: boolean }[] {
    return options.map((option) => ({ value: option.value, label: option.title, disabled: !option.is_enabled }));
}

function chosen(options: Option[]): string | undefined {
    return options.find((option) => option.is_selected)?.value;
}
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
                :readonly="locked"
                @focus="typingName = true"
                @blur="typingName = false"
                @change="typedName"
            />
        </label>

        <div class="flex flex-col gap-1.5">
            <span class="text-sm font-semibold text-primary-text">Sex</span>
            <SegmentedPicker label="Sex" :options="segments(about.sex_options)" :model-value="chosen(about.sex_options)" :disabled="locked" @update:model-value="(sex) => emit('answer', { sex: sex as 'male' | 'female' })" />
        </div>

        <div class="flex flex-col gap-1.5">
            <span class="text-sm font-semibold text-primary-text">Date of birth</span>
            <button
                v-if="!about.birth_date.date"
                type="button"
                data-test="set-birth-date"
                class="min-h-tap self-start rounded-xl bg-accent/15 px-4 font-medium text-accent active:opacity-70 disabled:opacity-50"
                :disabled="locked || about.birth_date.is_locked"
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
                :readonly="locked"
                class="min-h-tap self-start rounded-xl bg-background px-3.5 text-primary-text disabled:opacity-50"
                @change="pickedBirthDate"
            />
        </div>

        <div class="flex flex-col gap-1.5">
            <span class="text-sm font-semibold text-primary-text">Height</span>
            <SegmentedPicker label="Height unit" :options="segments(about.height.unit_options)" :model-value="chosen(about.height.unit_options)" :disabled="locked" @update:model-value="(unit) => emit('answer', { height: { unit: unit as HeightUnit } })" />
            <NumberField v-if="about.height.unit === 'cm'" label="Height" unit="cm" :model-value="about.height.cm" :commits-while-typing="false" :readonly="locked" @update:model-value="typedCm" />
            <div v-else class="flex gap-2">
                <NumberField class="flex-1" label="Feet" unit="ft" :model-value="about.height.feet" :commits-while-typing="false" :readonly="locked" @update:model-value="typedFeet" />
                <NumberField class="flex-1" label="Inches" unit="in" :model-value="about.height.inches" :commits-while-typing="false" :readonly="locked" @update:model-value="typedInches" />
            </div>
        </div>

        <div class="flex flex-col gap-1.5">
            <span class="text-sm font-semibold text-primary-text">Weight</span>
            <SegmentedPicker label="Weight unit" :options="segments(about.weight.unit_options)" :model-value="chosen(about.weight.unit_options)" :disabled="locked" @update:model-value="(unit) => emit('answer', { weight: { unit: unit as WeightUnit } })" />
            <NumberField
                label="Weight"
                :unit="about.weight.unit"
                :model-value="about.weight.value"
                :commits-while-typing="false"
                :readonly="locked"
                @update:model-value="typedWeight"
            />
        </div>
    </div>
</template>
