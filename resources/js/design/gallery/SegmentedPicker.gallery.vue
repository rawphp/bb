<script setup lang="ts">
import { ref } from 'vue';
import SegmentedPicker from '../SegmentedPicker.vue';

// SegmentedPicker (ONB-004, SCH-037) in each state.
const units = [
    { value: 'cm', label: 'cm' },
    { value: 'feetInches', label: 'ft/in' },
];
const sexes = [
    { value: 'male', label: 'Male' },
    { value: 'female', label: 'Female', disabled: true },
];
const unit = ref('cm');
</script>

<template>
    <div class="flex max-w-md flex-col gap-6">
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Default</h3>
            <SegmentedPicker v-model="unit" label="Height unit (default)" :options="units" />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Nothing chosen</h3>
            <SegmentedPicker :model-value="undefined" label="Height unit (nothing chosen)" :options="units" />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">One option disabled</h3>
            <SegmentedPicker model-value="male" label="Sex (one option disabled)" :options="sexes" />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Disabled</h3>
            <SegmentedPicker model-value="cm" label="Height unit (disabled)" :options="units" disabled />
        </div>
    </div>
</template>
