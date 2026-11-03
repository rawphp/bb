<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { AI_TIMEOUT_MS, readNow } from '@/app/call';
import { failureMessage, isTimeout } from '@/app/errors';
import type { RecipeGenerateInput, RecipeGenerateOutput } from '@/contracts/recipe_generate';
import SegmentedPicker from '@/design/SegmentedPicker.vue';
import RecipeDraftView from './RecipeDraftView.vue';
import { COMPLEXITIES, MEAL_TYPES, typedNumber, type RecipeComplexity, type RecipeMealType } from './options';

// Generate a recipe with AI (RCP-010), ported from rp-clone's RecipeGenerateView: target
// macros per serving, meal type, complexity, ingredients to use and preferences, then the
// confirm step. recipe_generate applies the profile's diet filters on the server, so this
// screen does not show them. The script holds what was typed and turns it into the input:
// numbers (a comma counts as a decimal point) and the ingredients, one per comma or line.
// The Pantry opens it with ?meal=<meal id>; no read gives that meal's targets as numbers
// yet, so the macros start empty.
const props = withDefaults(defineProps<{ payload?: RecipeGenerateOutput }>(), { payload: undefined });

const router = useRouter();

const protein = ref('');
const fat = ref('');
const carbs = ref('');
const kcal = ref('');
const mealType = ref<RecipeMealType>('dinner');
const complexity = ref<RecipeComplexity>('simple');
const ingredients = ref('');
const preferences = ref('');

const draft = ref<RecipeGenerateOutput | null>(props.payload ?? null);
const busy = ref(false);
const error = ref<string | null>(null);

const MEAL_TYPE_OPTIONS = MEAL_TYPES.map((option) => ({ value: option.value, label: option.title }));
const COMPLEXITY_OPTIONS = COMPLEXITIES.map((option) => ({ value: option.value, label: option.title }));

const MACROS = [
    { label: 'Protein (g)', model: protein },
    { label: 'Fat (g)', model: fat },
    { label: 'Carbs (g)', model: carbs },
    { label: 'Calories (optional)', model: kcal },
];

// What Generate sends, or null until protein, fat and carbs are numbers.
const input = computed<RecipeGenerateInput | null>(() => {
    const p = typedNumber(protein.value);
    const f = typedNumber(fat.value);
    const c = typedNumber(carbs.value);
    if (p === null || f === null || c === null) return null;

    return {
        protein: p,
        fat: f,
        carbs: c,
        kcal: typedNumber(kcal.value),
        meal_type: mealType.value,
        complexity: complexity.value,
        ingredients: ingredients.value
            .split(/[,\n]/)
            .map((item) => item.trim())
            .filter((item) => item !== ''),
        preferences: preferences.value.trim() || null,
    };
});

async function generate(): Promise<void> {
    if (!input.value || busy.value) return;
    busy.value = true;
    error.value = null;
    try {
        draft.value = await readNow<RecipeGenerateOutput>('recipe_generate', { ...input.value }, { timeoutMs: AI_TIMEOUT_MS });
    } catch (failure) {
        // The AI call can outlast a normal read; past its deadline the device is not offline.
        error.value = isTimeout(failure) ? 'The recipe took too long. Try again.' : failureMessage(failure, "Couldn't generate a recipe. Try again.");
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <RecipeDraftView v-if="draft" :draft="draft" />
    <main v-else class="bb-screen-content flex flex-col gap-4">
        <header class="grid grid-cols-[1fr_auto_1fr] items-center gap-2">
            <button type="button" data-test="cancel" class="min-h-tap justify-self-start text-accent" @click="router.back()">Cancel</button>
            <h1 class="text-center text-base font-semibold">Generate with AI</h1>
        </header>

        <section aria-labelledby="generate-macros">
            <h2 id="generate-macros" class="pb-1 text-lg font-semibold">Macros per serving</h2>
            <div class="divide-y divide-secondary-text/15 rounded-2xl bg-surface px-4">
                <label v-for="macro in MACROS" :key="macro.label" class="flex min-h-tap items-center justify-between gap-2">
                    <span>{{ macro.label }}</span>
                    <input
                        v-model="macro.model.value"
                        type="text"
                        inputmode="decimal"
                        placeholder="0"
                        :aria-label="macro.label"
                        :disabled="busy"
                        class="h-9 w-24 rounded-lg bg-background px-2 text-right tabular-nums"
                    />
                </label>
            </div>
        </section>

        <section aria-labelledby="generate-kind" class="flex flex-col gap-2">
            <h2 id="generate-kind" class="text-lg font-semibold">Meal type and complexity</h2>
            <SegmentedPicker v-model="mealType" label="Meal type" :options="MEAL_TYPE_OPTIONS" :disabled="busy" class="bg-secondary-text/15!" />
            <SegmentedPicker v-model="complexity" label="Complexity" :options="COMPLEXITY_OPTIONS" :disabled="busy" class="bg-secondary-text/15!" />
        </section>

        <section aria-labelledby="generate-ingredients">
            <h2 id="generate-ingredients" class="pb-1 text-lg font-semibold">Ingredients to use</h2>
            <textarea
                v-model="ingredients"
                rows="2"
                aria-label="Ingredients to use"
                placeholder="chicken breast, rice, broccoli"
                :disabled="busy"
                class="field-sizing-content min-h-tap w-full resize-none rounded-2xl bg-surface px-4 py-3 placeholder:text-secondary-text focus:outline-none"
            />
            <p class="pt-1 text-sm text-secondary-text">Optional. Separate them with commas.</p>
        </section>

        <section aria-labelledby="generate-preferences">
            <h2 id="generate-preferences" class="pb-1 text-lg font-semibold">Preferences</h2>
            <textarea
                v-model="preferences"
                rows="2"
                aria-label="Preferences"
                placeholder="Anything else: cuisine, equipment, what to avoid"
                maxlength="500"
                :disabled="busy"
                class="field-sizing-content min-h-tap w-full resize-none rounded-2xl bg-surface px-4 py-3 placeholder:text-secondary-text focus:outline-none"
            />
        </section>

        <p v-if="error" role="alert" class="text-sm text-tone-warning">{{ error }}</p>

        <button
            type="button"
            data-test="generate"
            class="min-h-tap w-full rounded-full bg-accent font-semibold text-on-accent disabled:opacity-40"
            :disabled="input === null || busy"
            @click="generate"
        >
            {{ busy ? 'Generating…' : 'Generate' }}
        </button>
    </main>
</template>
