<script setup lang="ts">
import { ref } from 'vue';
import ChoiceList, { type Choice } from '../ChoiceList.vue';

// ChoiceList (UI-006) in each VIEW-004 state.
const choices: Choice<string>[] = [
    { id: 'cut', title: 'Lose fat', detail: 'About 0.5% of body weight a week', isRecommended: true },
    { id: 'maintain', title: 'Maintain' },
    { id: 'bulk', title: 'Build muscle', detail: 'Finish the current phase first', isEnabled: false },
];
const selection = ref<string | null>('cut');
</script>

<template>
    <div class="flex max-w-md flex-col gap-6">
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Default</h3>
            <ChoiceList v-model="selection" label="Goal (default)" :choices="choices" />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Empty</h3>
            <ChoiceList :model-value="null" label="Goal (empty)" :choices="[]" />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Loading</h3>
            <ChoiceList :model-value="null" label="Goal (loading)" :choices="[]" loading />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Error</h3>
            <ChoiceList model-value="maintain" label="Goal (error)" :choices="choices" error="Couldn't save your choice. Try again." />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Pending</h3>
            <ChoiceList model-value="maintain" label="Goal (pending)" :choices="choices" pending />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Disabled</h3>
            <ChoiceList model-value="cut" label="Goal (disabled)" :choices="choices" disabled />
        </div>
    </div>
</template>
