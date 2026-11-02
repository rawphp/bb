<script setup lang="ts">
import ActionMenu, { type ActionMenuItem } from '../ActionMenu.vue';

// ActionMenu in each VIEW-004 state. Tap a button to open its menu.
const items: ActionMenuItem[] = [
    { key: 'add-day', title: 'Add day' },
    { key: 'rates', title: 'Progression rates', detail: 'Change a muscle from your next session' },
    { key: 'copy', title: 'Copy a past mesocycle', detail: 'You have no past mesocycles', disabled: true },
    { key: 'end', title: 'End meso', destructive: true },
];
</script>

<template>
    <div class="flex max-w-md flex-col gap-6">
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Default</h3>
            <p class="mb-2 text-sm text-secondary-text">A detail line, a disabled item and a destructive one.</p>
            <ActionMenu label="Mesocycle menu (default)" :items="items">⋯</ActionMenu>
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Empty</h3>
            <p class="mb-2 text-sm text-secondary-text">With no items there is nothing to open, so the button is off.</p>
            <ActionMenu label="Mesocycle menu (empty)" :items="[]" disabled>⋯</ActionMenu>
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Loading</h3>
            <p class="mb-2 text-sm text-secondary-text">Until the screen's read lands, the button is off.</p>
            <ActionMenu label="Mesocycle menu (loading)" :items="items" disabled>⋯</ActionMenu>
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Error</h3>
            <p class="mb-2 text-sm text-secondary-text">A failed action leaves the menu as it was; the screen shows the message.</p>
            <ActionMenu label="Mesocycle menu (error)" :items="items">⋯</ActionMenu>
            <p role="alert" class="mt-2 text-sm text-tone-warning">The day could not be added.</p>
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Pending</h3>
            <p class="mb-2 text-sm text-secondary-text">While a chosen action is still sending, the button is off.</p>
            <ActionMenu label="Mesocycle menu (pending)" :items="items" disabled>⋯</ActionMenu>
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Disabled</h3>
            <ActionMenu label="Mesocycle menu (disabled)" :items="items" disabled>⋯</ActionMenu>
        </div>
    </div>
</template>
