<script setup lang="ts">
import { useRead } from '@/app/useRead';
import { useWrite } from '@/app/useWrite';
import type { MealSavedMealLoadInput } from '@/contracts/meal_saved_meal_load';
import type { SavedMealsShowOutput } from '@/contracts/saved_meals_show';
import BottomSheet from '@/design/BottomSheet.vue';
import SavedMealRowView from './SavedMealRowView.vue';
import SavedMealsStatus from './SavedMealsStatus.vue';

// MEAL-070: the sheet that picks a saved meal to load into a meal, ported from rp-clone's
// SavedMealPickerSheet. The server keeps locked foods at their saved amounts and re-fits the
// rest; the sheet sends the pick and says when it is done, so the meal screen can read again.
const props = defineProps<{ open: boolean; mealId: string; payload?: SavedMealsShowOutput }>();

const emit = defineEmits<{ cancel: []; loaded: [] }>();

const { data, failure, load } = useRead<SavedMealsShowOutput>('saved_meals_show', {}, 'Your saved meals could not be loaded.', props.payload);
const { send, busy, error } = useWrite(async () => {});

async function pick(savedMealId: string): Promise<void> {
    const input: MealSavedMealLoadInput = { meal_id: props.mealId, saved_meal_id: savedMealId };
    if ((await send('meal_saved_meal_load', { ...input }, 'The saved meal could not be loaded. Try again.')) === 'done') emit('loaded');
}
</script>

<template>
    <BottomSheet
        :open="open"
        title="Load saved meal"
        :save-title="null"
        :cancel-disabled="busy"
        :dismissible="!busy"
        :error="error"
        @cancel="emit('cancel')"
    >
        <div class="space-y-3 p-4">
            <SavedMealsStatus
                :loaded="data !== undefined"
                :failure="failure"
                :empty-text="data && !data.meals.length ? (data.empty_text ?? null) : null"
                :error="null"
                @retry="load"
            />
            <ul v-if="data?.meals.length" class="flex flex-col gap-2">
                <li v-for="meal in data.meals" :key="meal.saved_meal_id">
                    <button type="button" data-pick class="block w-full text-left active:opacity-70 disabled:opacity-60" :disabled="busy" @click="pick(meal.saved_meal_id)">
                        <SavedMealRowView :meal="meal" />
                    </button>
                </li>
            </ul>
        </div>
    </BottomSheet>
</template>
