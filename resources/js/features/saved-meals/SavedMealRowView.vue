<script setup lang="ts">
import type { SavedMealsShowOutput } from '@/contracts/saved_meals_show';
import ValueRow, { type RowAction } from '@/design/ValueRow.vue';

// One saved meal: its name and the server's detail ("3 foods"), ported from rp-clone's
// SavedMealRowView. The list passes Rename and Delete as swipe actions; the picker passes none.
withDefaults(
    defineProps<{
        meal: SavedMealsShowOutput['meals'][number];
        actions?: RowAction[];
        /** The detail is waiting to sync (OFF-008). */
        pending?: boolean;
        disabled?: boolean;
    }>(),
    { actions: () => [], pending: false, disabled: false },
);

defineEmits<{ action: [id: string] }>();
</script>

<template>
    <ValueRow
        :label="meal.name"
        :detail="meal.detail"
        :fields="[]"
        :swipe-actions="actions"
        :pending="pending"
        :disabled="disabled"
        @action="(id) => $emit('action', id)"
    />
</template>
