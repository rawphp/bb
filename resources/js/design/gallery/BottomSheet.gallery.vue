<script setup lang="ts">
import { ref } from 'vue';
import BottomSheet, { type SheetHeight } from '../BottomSheet.vue';

// BottomSheet (UI-005, UI-022, PWA-023) in each VIEW-004 state. Each opens from its own button.
interface State {
    name: string;
    content?: boolean;
    heights?: SheetHeight[];
    dismissible?: boolean;
    saveTitle?: string | null;
    loading?: boolean;
    error?: string;
    pending?: boolean;
    saveDisabled?: boolean;
}

const states: State[] = [
    { name: 'Default', content: true },
    { name: 'Half and full', content: true, heights: ['half', 'full'] },
    { name: 'No dismiss', content: true, dismissible: false },
    { name: 'No Save', content: true, saveTitle: null },
    { name: 'Empty' },
    { name: 'Loading', loading: true },
    { name: 'Error', content: true, error: "Couldn't copy the foods. Try again." },
    { name: 'Pending', content: true, pending: true },
    { name: 'Disabled', content: true, saveDisabled: true },
];

const open = ref<string | null>(null);
</script>

<template>
    <div class="flex max-w-md flex-col gap-4">
        <div v-for="state in states" :key="state.name">
            <h3 class="mb-2 text-sm text-secondary-text">{{ state.name }}</h3>
            <button type="button" class="min-h-tap rounded-lg bg-surface px-4 text-accent" @click="open = state.name">
                Open {{ state.name.toLowerCase() }} sheet
            </button>
            <BottomSheet
                :open="open === state.name"
                title="Copy foods"
                :save-title="state.saveTitle"
                :heights="state.heights"
                :dismissible="state.dismissible"
                :loading="state.loading"
                :error="state.error"
                :pending="state.pending"
                :save-disabled="state.saveDisabled"
                @cancel="open = null"
                @save="open = null"
            >
                <ul v-if="state.content" class="flex flex-col">
                    <li
                        v-for="meal in ['Breakfast', 'Lunch', 'Dinner', 'Snack']"
                        :key="meal"
                        class="flex min-h-tap items-center border-b border-secondary-text/25 px-4"
                    >
                        {{ meal }}
                    </li>
                </ul>
            </BottomSheet>
        </div>
    </div>
</template>
