<script setup lang="ts">
import type { FoodBarcodeLookupOutput } from '@/contracts/food_barcode_lookup';

// FOOD-041: a barcode that found a food: its name and nutrition, with Add to meal.
defineProps<{ food: NonNullable<FoodBarcodeLookupOutput['food']>; disabled?: boolean }>();
defineEmits<{ add: [] }>();
</script>

<template>
    <section class="space-y-2 rounded-2xl bg-surface p-3.5" data-test="found">
        <h3 class="font-semibold text-primary-text">{{ food.name }}</h3>
        <p v-if="food.detail" class="text-sm text-secondary-text">{{ food.detail }}</p>
        <p class="text-xs text-secondary-text">{{ food.raw.kcal }} · {{ food.raw.protein }} · {{ food.raw.fat }} · {{ food.raw.carbs }} {{ food.basis_text }}</p>
        <button
            type="button"
            :disabled="disabled"
            class="min-h-tap w-full rounded-full bg-accent font-semibold text-on-accent active:opacity-70 disabled:opacity-50"
            data-test="add-found"
            @click="$emit('add')"
        >
            Add to meal
        </button>
    </section>
</template>
