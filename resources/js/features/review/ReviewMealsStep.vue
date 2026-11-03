<script setup lang="ts">
import type { ReviewShowOutput } from '@/contracts/review_show';
import ReviewNote from './ReviewNote.vue';

type Meals = NonNullable<ReviewShowOutput['meals']>;

// Step 2 (REV-003): how many meals were checked in, and each unchecked one to check in now.
defineProps<{ meals: Meals; busy: boolean }>();
const emit = defineEmits<{ checkIn: [mealId: string] }>();
</script>

<template>
    <div class="flex flex-col gap-3" data-test="step-meals">
        <ReviewNote :text="meals.message.text" :tone="meals.message.tone" />
        <ul v-if="meals.unchecked.length" class="flex flex-col gap-3">
            <li v-for="meal in meals.unchecked" :key="meal.meal_id" data-test="unchecked" class="flex items-center justify-between gap-2">
                <span class="flex flex-col gap-0.5">
                    <span class="text-primary-text">{{ meal.name }}</span>
                    <span class="text-sm text-secondary-text">{{ meal.date_label }}</span>
                </span>
                <button
                    type="button"
                    data-test="check-in"
                    class="min-h-tap shrink-0 rounded-xl bg-accent/15 px-3 font-medium text-accent active:opacity-70 disabled:opacity-50"
                    :disabled="busy"
                    @click="emit('checkIn', meal.meal_id)"
                >
                    {{ meals.check_in_title }}
                </button>
            </li>
        </ul>
    </div>
</template>
