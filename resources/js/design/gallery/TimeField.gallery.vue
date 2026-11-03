<script setup lang="ts">
import { ref } from 'vue';
import TimeField from '../TimeField.vue';

// TimeField (ONB-011, SCH-037) in each state.
const start = ref('07:30');
</script>

<template>
    <div class="flex max-w-md flex-col gap-6">
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Default</h3>
            <TimeField v-model="start" label="Window starts" class="rounded-2xl bg-surface px-4" />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Disabled</h3>
            <TimeField model-value="17:30" label="Training starts (disabled)" disabled class="rounded-2xl bg-surface px-4" />
        </div>
    </div>
</template>
