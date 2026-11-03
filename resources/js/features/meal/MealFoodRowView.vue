<script setup lang="ts">
import type { MealShowOutput } from '@/contracts/meal_show';
import MealEntryRow from './MealEntryRow.vue';

// MEAL-001, MEAL-004, MEAL-005, MEAL-008: a food with its amount, macros, + and − buttons
// and lock; swiping it left shows Delete. Ported from rp-clone's MealFoodRowView. Tapping
// the amount opens the amount sheet. `pending` marks the server's numbers as waiting to sync (OFF-008).
type Entry = MealShowOutput['entries'][number];

defineProps<{ entry: Entry; busy: boolean; pending: boolean }>();

const emit = defineEmits<{ lock: []; minus: []; plus: []; edit: []; delete: [] }>();
</script>

<template>
    <MealEntryRow :entry="entry" :busy="busy" :pending="pending" @lock="emit('lock')" @minus="emit('minus')" @plus="emit('plus')" @delete="emit('delete')">
        <template #title>
            <div class="min-w-0 flex-1">
                <h2 class="font-semibold text-primary-text">{{ entry.name }}</h2>
                <p v-if="entry.weighing === 'cooked'" class="text-xs text-secondary-text">Cooked weight</p>
            </div>
        </template>
        <template #amount>
            <button
                type="button"
                class="min-h-8 rounded-xl bg-background px-2.5 text-sm font-semibold text-primary-text tabular-nums"
                :aria-label="`${entry.name} amount, ${entry.amount_text}`"
                :disabled="busy"
                data-test="amount"
                @click="emit('edit')"
            >
                {{ entry.amount_text }}
            </button>
        </template>
    </MealEntryRow>
</template>
