<script setup lang="ts">
import type { MealShowOutput } from '@/contracts/meal_show';
import MealEntryRow from './MealEntryRow.vue';

// RCP-001, RCP-002, MEAL-008: servings of a recipe in the meal with their macros, + and −
// buttons and lock; swiping it left shows Delete. Ported from rp-clone's MealRecipeRowView.
// `pending` marks the server's numbers as waiting to sync (OFF-008).
type Entry = MealShowOutput['entries'][number];

defineProps<{ entry: Entry; busy: boolean; pending: boolean }>();

const emit = defineEmits<{ lock: []; minus: []; plus: []; delete: [] }>();
</script>

<template>
    <MealEntryRow :entry="entry" :busy="busy" :pending="pending" @lock="emit('lock')" @minus="emit('minus')" @plus="emit('plus')" @delete="emit('delete')">
        <template #title>
            <svg viewBox="0 0 24 24" class="size-6 shrink-0 text-accent" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" aria-hidden="true">
                <path d="M5 4h11a2 2 0 0 1 2 2v14H7a2 2 0 0 1-2-2zM5 18a2 2 0 0 1 2-2h11" />
            </svg>
            <div class="min-w-0 flex-1">
                <h3 class="font-semibold text-primary-text">{{ entry.name }}</h3>
                <p class="text-xs text-secondary-text">Recipe</p>
            </div>
        </template>
        <template #amount>
            <span class="flex min-h-8 items-center rounded-xl bg-background px-2.5 text-sm font-semibold text-primary-text tabular-nums" data-test="amount">
                <span class="sr-only">{{ entry.name }} servings, </span>{{ entry.amount_text }}
            </span>
        </template>
    </MealEntryRow>
</template>
