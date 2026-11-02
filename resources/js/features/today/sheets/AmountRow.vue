<script setup lang="ts">
// A typed amount with its unit, in the colour of its macro (UI-011). The text is read
// when the sheet saves, so the field keeps exactly what was typed.
const text = defineModel<string>({ required: true });

defineProps<{ title: string; unit: 'kcal' | 'g'; macro: 'calories' | 'protein' | 'fat' | 'carbs'; name: string }>();

const colours = {
    calories: 'text-macro-calories',
    protein: 'text-macro-protein',
    fat: 'text-macro-fat',
    carbs: 'text-macro-carbs',
} as const;
</script>

<template>
    <label class="flex min-h-tap items-center gap-2 px-4" data-test="amount-row">
        <span :class="colours[macro]" data-test="amount-title">{{ title }}</span>
        <input
            v-model="text"
            :name="name"
            inputmode="decimal"
            :placeholder="title"
            :aria-label="`${title} in ${unit === 'g' ? 'grams' : 'kilocalories'}`"
            class="min-w-0 flex-1 bg-transparent py-2 text-right tabular-nums outline-none placeholder:text-secondary-text/60"
        />
        <span class="text-secondary-text">{{ unit }}</span>
    </label>
</template>
