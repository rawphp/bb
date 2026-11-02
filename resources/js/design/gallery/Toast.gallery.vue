<script setup lang="ts">
import Toast from '../Toast.vue';

// Toast (UI-010) in each VIEW-004 state. Undo here calls the real undo_apply, which refuses these made-up tokens.
const toast = 'Meals 3 and 4 re-targeted';
</script>

<template>
    <div class="flex max-w-md flex-col gap-6">
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Default</h3>
            <Toast :result="{ toast, undo: 'gallery-default' }" />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Empty</h3>
            <Toast :result="{ toast: '', undo: 'gallery-empty' }" />
            <p class="text-sm text-secondary-text">No toast draws nothing.</p>
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Loading</h3>
            <Toast :result="{ toast, undo: 'gallery-loading' }" loading />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Error</h3>
            <Toast :result="{ toast, undo: 'gallery-error' }" error="Undo isn't possible: a later change depends on this one." />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Pending</h3>
            <Toast :result="{ toast, undo: 'gallery-pending' }" pending />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Disabled</h3>
            <Toast :result="{ toast, undo: 'gallery-disabled' }" disabled />
        </div>
    </div>
</template>
