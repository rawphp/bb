<script setup lang="ts">
import { watch } from 'vue';
import { useRead } from '@/app/useRead';
import { useWrite } from '@/app/useWrite';
import type { MealSavedMealLoadInput } from '@/contracts/meal_saved_meal_load';
import type { SavedMealsShowOutput } from '@/contracts/saved_meals_show';
import BottomSheet from '@/design/BottomSheet.vue';
import SavedMealRowView from './SavedMealRowView.vue';
import SavedMealsStatus from './SavedMealsStatus.vue';

// MEAL-070: the sheet that picks a saved meal to load into a meal, ported from rp-clone's
// SavedMealPickerSheet. The server keeps locked foods at their saved amounts and re-fits the
// rest. `reload` is the meal screen's read, which useWrite runs after the load, or fresh after
// one that got no final answer (CAP-014). The list is read again each time the sheet opens.
const props = defineProps<{
    open: boolean;
    mealId: string;
    reload: (options?: { fresh?: boolean }) => Promise<void>;
    payload?: SavedMealsShowOutput;
}>();

const emit = defineEmits<{ cancel: []; loaded: [] }>();

const { data, failure, load } = useRead<SavedMealsShowOutput>('saved_meals_show', {}, 'Your saved meals could not be loaded.', props.payload);
const { send, busy, error } = useWrite((options) => props.reload(options));

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        error.value = null;
        if (!props.payload) void load().catch(() => undefined);
    },
);

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
                :data="data"
                :failure="failure"
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
