<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRead } from '@/app/useRead';
import { useWrite } from '@/app/useWrite';
import type { SavedMealDeleteInput } from '@/contracts/saved_meal_delete';
import type { SavedMealRenameInput } from '@/contracts/saved_meal_rename';
import type { SavedMealsShowOutput } from '@/contracts/saved_meals_show';
import BottomSheet from '@/design/BottomSheet.vue';
import type { RowAction } from '@/design/ValueRow.vue';
import SavedMealRowView from './SavedMealRowView.vue';
import SavedMealsStatus from './SavedMealsStatus.vue';

// The Saved meals manager (MEAL-071), ported from rp-clone's SavedMealsView.swift: the saved
// meals by name, to rename and delete. `pending` marks the server's detail as waiting to sync (OFF-008).
type Meal = SavedMealsShowOutput['meals'][number];

const props = withDefaults(defineProps<{ payload?: SavedMealsShowOutput; pending?: boolean }>(), { pending: false });

const { data, failure, load } = useRead<SavedMealsShowOutput>('saved_meals_show', {}, 'Your saved meals could not be loaded.', props.payload);
const { send, busy, error } = useWrite(load);
// The rename sheet's own write, so its refusal shows in the sheet and not above the list.
const renameWrite = useWrite(load);

// Left to right in the swipe tray, so Delete sits at the edge as in rp-clone.
const ACTIONS: RowAction[] = [
    { id: 'rename', title: 'Rename' },
    { id: 'delete', title: 'Delete', isDestructive: true },
];

const renaming = ref<Meal | null>(null);
const newName = ref('');
const trimmed = computed(() => newName.value.trim());

function act(meal: Meal, action: string): void {
    if (action === 'delete') void send('saved_meal_delete', { saved_meal_id: meal.saved_meal_id } satisfies SavedMealDeleteInput, 'The saved meal could not be deleted. Try again.');
    if (action === 'rename') {
        renaming.value = meal;
        newName.value = meal.name;
        renameWrite.error.value = null;
    }
}

// A blank name cannot be saved; the same name sends nothing.
async function rename(): Promise<void> {
    const meal = renaming.value;
    if (!meal || trimmed.value === '') return;
    if (trimmed.value === meal.name) {
        renaming.value = null;
        return;
    }
    const input: SavedMealRenameInput = { saved_meal_id: meal.saved_meal_id, name: trimmed.value };
    if ((await renameWrite.send('saved_meal_rename', { ...input }, 'The saved meal could not be renamed. Try again.')) === 'done') renaming.value = null;
}
</script>

<template>
    <main class="bb-screen-content space-y-4">
        <h1 class="text-3xl font-bold">Saved meals</h1>

        <SavedMealsStatus
            :loaded="data !== undefined"
            :failure="failure"
            :empty-text="data && !data.meals.length ? (data.empty_text ?? null) : null"
            :error="error"
            @retry="load"
        />
        <ul v-if="data?.meals.length" class="flex flex-col gap-2">
            <li v-for="meal in data.meals" :key="meal.saved_meal_id">
                <SavedMealRowView :meal="meal" :actions="ACTIONS" :pending="pending" :disabled="busy" @action="(action) => act(meal, action)" />
            </li>
        </ul>

        <BottomSheet
            :open="renaming !== null"
            title="Rename saved meal"
            :heights="['half']"
            :save-disabled="trimmed === '' || renameWrite.busy.value"
            :cancel-disabled="renameWrite.busy.value"
            :dismissible="!renameWrite.busy.value"
            :error="renameWrite.error.value"
            @cancel="renaming = null"
            @save="rename"
        >
            <form class="p-4" @submit.prevent="rename">
                <label class="flex flex-col gap-1.5">
                    <span class="text-sm text-secondary-text">Name</span>
                    <input
                        v-model="newName"
                        name="saved-meal-name"
                        type="text"
                        autocomplete="off"
                        :disabled="renameWrite.busy.value"
                        class="min-h-tap w-full rounded-xl bg-background px-3.5 text-base text-primary-text"
                    />
                </label>
            </form>
        </BottomSheet>
    </main>
</template>
