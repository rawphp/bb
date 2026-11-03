<script setup lang="ts">
import { ref } from 'vue';
import ToggleSwitch from '../ToggleSwitch.vue';

// ToggleSwitch (AGT-020, SCH-037) in each state.
const coach = ref(true);
const training = ref(false);
</script>

<template>
    <div class="flex max-w-md flex-col gap-6">
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">On</h3>
            <ToggleSwitch v-model="coach" label="Coach" class="rounded-2xl bg-surface px-4 py-2" />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Off</h3>
            <ToggleSwitch v-model="training" label="Train on Wednesdays" class="rounded-2xl bg-surface px-4 py-2" />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Busy</h3>
            <ToggleSwitch :model-value="true" label="Coach (saving)" disabled busy class="rounded-2xl bg-surface px-4 py-2" />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Disabled</h3>
            <ToggleSwitch :model-value="false" label="Micro plates (disabled)" disabled class="rounded-2xl bg-surface px-4 py-2" />
        </div>
    </div>
</template>
