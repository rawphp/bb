<script setup lang="ts">
import { ref } from 'vue';
import ValueRow, { type RowAction, type ValueField } from '../ValueRow.vue';

// ValueRow (UI-008) in each VIEW-004 state, as a food row and as a gym set row (UI-020).
const grams = ref('150');
const food = (value: string): ValueField[] => [{ id: 'grams', title: 'Grams', unit: 'g', kind: 'decimal', value }];
const set: ValueField[] = [
    { id: 'weight', title: 'Weight', unit: 'kg', kind: 'decimal', value: '100' },
    { id: 'reps', title: 'Reps', kind: 'whole', value: '8' },
];
const foodMenu: RowAction[] = [{ id: 'cooked', title: 'Switch to cooked' }];
const foodSwipe: RowAction[] = [
    { id: 'edit', title: 'Edit' },
    { id: 'delete', title: 'Delete', isDestructive: true },
];
const setMenu: RowAction[] = [
    { id: 'add-below', title: 'Add set below' },
    { id: 'skip', title: 'Skip set' },
    { id: 'delete', title: 'Delete set', isDestructive: true },
];
const last = ref('');
</script>

<template>
    <div class="flex max-w-md flex-col gap-6">
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Default</h3>
            <ValueRow
                label="Chicken breast"
                detail="Raw"
                :fields="food(grams)"
                :menu-actions="foodMenu"
                :swipe-actions="foodSwipe"
                @update:field="(_, value) => (grams = value)"
                @action="(id) => (last = id)"
            />
            <p class="mt-1 text-xs text-secondary-text">Last action: {{ last || 'none' }}</p>
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Gym</h3>
            <ValueRow label="Set 1" :fields="set" :menu-actions="setMenu" gym />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Empty</h3>
            <ValueRow label="Set 2" :fields="set.map((field) => ({ ...field, value: '' }))" :menu-actions="setMenu" gym />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Loading</h3>
            <ValueRow label="Chicken breast" :fields="food('')" :menu-actions="foodMenu" loading />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Error</h3>
            <ValueRow label="Chicken breast" :fields="food('150')" error="Couldn't save the grams. Try again." />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Pending</h3>
            <ValueRow label="Set 1" :fields="set" :menu-actions="setMenu" gym pending />
        </div>
        <div>
            <h3 class="mb-2 text-sm text-secondary-text">Disabled</h3>
            <ValueRow label="Chicken breast" :fields="food('150')" :menu-actions="foodMenu" :swipe-actions="foodSwipe" disabled />
        </div>
    </div>
</template>
