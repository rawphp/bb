<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import type { RecipeGenerateOutput } from '@/contracts/recipe_generate';
import type { RecipeImportOutput } from '@/contracts/recipe_import';
import BottomSheet from '@/design/BottomSheet.vue';
import type { PickedFood } from '@/features/pantry/options';
import PantryView from '@/features/pantry/PantryView.vue';
import RecipeEditorView, { type RecipeDraft } from './RecipeEditorView.vue';
import { servingsText, typedNumber } from './options';

// The confirm step of an import (RCP-003) or an AI draft (RCP-010), ported from rp-clone's
// RecipeDraftView: each item with the food the server matched, or "No match". The user
// changes the food (the Pantry opens in a sheet with the item's name searched), fixes the
// grams or removes the item, then continues to the editor, where the meal type is picked and
// the recipe saved. The script holds the user's changes to the draft.
const props = defineProps<{ draft: RecipeGenerateOutput | RecipeImportOutput }>();

type Entry = { key: number; name: string; food_id: string | null; matchText: string; grams: string };

const router = useRouter();

const entries = ref<Entry[]>(
    props.draft.items.map((item, key) => ({ key, name: item.name, food_id: item.food_id ?? null, matchText: item.match_text, grams: String(item.grams) })),
);
const changing = ref<Entry | null>(null);
const editing = ref<RecipeDraft | null>(null);

function remove(key: number): void {
    entries.value = entries.value.filter((entry) => entry.key !== key);
}

function useFood(foods: PickedFood[]): void {
    const food = foods[0];
    const entry = entries.value.find((e) => e.key === changing.value?.key);
    if (food && entry) {
        entry.food_id = food.food_id;
        entry.matchText = food.name;
    }
    changing.value = null;
}

// Continue waits until the editor can be given every item: each has a food and an amount
// typed as a number. Whether the amounts are allowed is recipe_save's to say (CAP-012).
const ready = computed(() => entries.value.length > 0 && entries.value.every((entry) => entry.food_id !== null && typedNumber(entry.grams) !== null));

function proceed(): void {
    if (!ready.value) return;
    editing.value = {
        name: props.draft.name,
        servings: props.draft.servings,
        meal_type: props.draft.meal_type ?? null,
        steps: props.draft.steps.map(String),
        ingredients: entries.value.map((entry) => ({ food_id: entry.food_id!, name: entry.matchText, grams: typedNumber(entry.grams)! })),
    };
}

const stepsText = computed(() => `${props.draft.steps.length} ${props.draft.steps.length === 1 ? 'step' : 'steps'}`);
</script>

<template>
    <RecipeEditorView v-if="editing" :draft="editing" @back="editing = null" />
    <main v-else class="bb-screen-content flex min-h-full flex-col gap-4 pb-0">
        <header class="grid grid-cols-[1fr_auto_1fr] items-center gap-2">
            <button type="button" data-test="cancel" class="min-h-tap justify-self-start text-accent" @click="router.back()">Cancel</button>
            <h1 class="text-center text-base font-semibold">Confirm ingredients</h1>
        </header>

        <section class="rounded-2xl bg-surface px-4 py-3">
            <p class="text-xl font-semibold">{{ draft.name }}</p>
            <p class="text-sm text-secondary-text">{{ servingsText(draft.servings) }} · {{ stepsText }}</p>
        </section>

        <section aria-labelledby="draft-items">
            <h2 id="draft-items" class="pb-1 text-lg font-semibold">Check the ingredients</h2>
            <ul class="divide-y divide-secondary-text/15 rounded-2xl bg-surface px-4">
                <li v-for="entry in entries" :key="entry.key" data-test="draft-item" class="flex flex-col gap-1 py-2">
                    <span class="flex items-start gap-2">
                        <span class="min-w-0 flex-1">
                            <span v-if="entry.food_id === null" data-test="no-match" class="flex items-center gap-1.5 font-semibold text-tone-warning">
                                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4 shrink-0" fill="currentColor"><path d="M12 2 1 21h22L12 2Zm1 15h-2v-2h2v2Zm0-4h-2V9h2v4Z" /></svg>
                                {{ entry.matchText }}
                            </span>
                            <span v-else class="block">{{ entry.matchText }}</span>
                            <span class="block text-sm text-secondary-text">From the recipe: {{ entry.name }}</span>
                        </span>
                        <input
                            v-model="entry.grams"
                            type="text"
                            inputmode="decimal"
                            placeholder="0"
                            :aria-label="`${entry.name} grams`"
                            class="h-9 w-18 rounded-lg bg-background px-2 text-right tabular-nums"
                        />
                        <span class="pt-1.5 text-secondary-text">g</span>
                    </span>
                    <span class="flex items-center justify-between">
                        <button
                            type="button"
                            data-test="change-food"
                            class="min-h-tap text-sm font-semibold text-accent"
                            :aria-label="`${entry.food_id === null ? 'Pick a food' : 'Change food'} for ${entry.name}`"
                            @click="changing = entry"
                        >
                            {{ entry.food_id === null ? 'Pick a food' : 'Change food' }}
                        </button>
                        <button type="button" class="min-h-tap text-sm text-tone-warning" :aria-label="`Remove ${entry.name}`" @click="remove(entry.key)">Remove</button>
                    </span>
                </li>
            </ul>
            <p class="pt-1.5 text-sm text-secondary-text">Each item is matched to a food in the database. Change a match, fix the amount, or remove an item.</p>
        </section>

        <div class="sticky bottom-0 -mx-4 mt-auto flex flex-col gap-1.5 bg-background/90 px-4 py-2 backdrop-blur">
            <button
                type="button"
                data-test="continue"
                class="min-h-tap w-full rounded-full bg-accent font-semibold text-on-accent disabled:opacity-40"
                :disabled="!ready"
                @click="proceed"
            >
                Continue
            </button>
        </div>

        <BottomSheet :open="changing !== null" :title="changing?.food_id === null ? 'Pick a food' : 'Change food'" :save-title="null" @cancel="changing = null">
            <PantryView v-if="changing" purpose="recipeMatch" :search="changing.name.slice(0, 100)" @picked="useFood" @cancel="changing = null" />
        </BottomSheet>
    </main>
</template>
