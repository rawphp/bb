<script lang="ts">
/** A recipe not saved yet: a drafted one the user confirmed (RCP-003, RCP-010). */
export type RecipeDraft = {
    name: string;
    servings: number;
    meal_type: string | null;
    steps: string[];
    ingredients: { food_id: string; name: string; grams: number }[];
};
</script>

<script setup lang="ts">
import { computed, ref, watch, type Ref } from 'vue';
import { useRouter } from 'vue-router';
import ReadState from '@/app/ReadState.vue';
import type { ReadFailure } from '@/app/screenRead';
import { ulid } from '@/app/ulid';
import { useRead } from '@/app/useRead';
import { useWrite } from '@/app/useWrite';
import type { RecipeDeleteInput } from '@/contracts/recipe_delete';
import type { RecipeSaveInput } from '@/contracts/recipe_save';
import type { RecipeShowOutput } from '@/contracts/recipe_show';
import BottomSheet from '@/design/BottomSheet.vue';
import PendingMark from '@/design/PendingMark.vue';
import StatusPill from '@/design/StatusPill.vue';
import type { PickedFood } from '@/features/pantry/options';
import PantryView from '@/features/pantry/PantryView.vue';
import SegmentedPicker from '@/design/SegmentedPicker.vue';
import { isMealType, MEAL_TYPES, typedNumber, type RecipeMealType } from './options';

// A recipe to read or change (RCP-001, RCP-021), ported from rp-clone's RecipeEditorView:
// name, servings, meal type, ingredients with amounts, the macros of one serving, and the
// steps. A library recipe is read-only. The script holds what the user has typed; the
// server checks it on save and answers in its own words (CAP-012). The per-serving macros
// are the saved recipe's, from recipe_show: the device works out no macros, so a recipe
// not saved yet has none to show. Add ingredient opens the Pantry in a sheet, so what was
// typed stays. `pending` dims the server's macros (OFF-008).
const props = withDefaults(
    defineProps<{
        /** A /dev/screens fixture: the saved recipe, shown without a read. */
        payload?: RecipeShowOutput;
        /** The saved recipe to read. */
        recipeId?: string;
        /** A confirmed draft, opened unsaved. */
        draft?: RecipeDraft;
        pending?: boolean;
    }>(),
    { payload: undefined, recipeId: undefined, draft: undefined, pending: false },
);

const emit = defineEmits<{ back: [] }>();

type Ingredient = { key: number; food_id: string; name: string; grams: string; isCooked: boolean; canToggleCooked: boolean; amountText: string };
type Step = { key: number; text: string };

const WEIGHINGS: { value: 'raw' | 'cooked'; label: string }[] = [
    { value: 'raw', label: 'Raw' },
    { value: 'cooked', label: 'Cooked' },
];

const router = useRouter();

const isSaved = props.payload !== undefined || props.recipeId !== undefined;
const noRead = async (): Promise<void> => {};
let data: Ref<RecipeShowOutput | undefined> = ref();
let failure: Ref<ReadFailure | null> = ref(null);
let load: (options?: { fresh?: boolean }) => Promise<void> = noRead;
if (isSaved) {
    ({ data, failure, load } = useRead<RecipeShowOutput>('recipe_show', { recipe_id: props.recipeId ?? props.payload!.recipe_id }, 'The recipe could not be loaded.', props.payload));
}

let nextKey = 0;
const id = ref(ulid());
const name = ref('');
const servings = ref('1');
const mealType = ref<RecipeMealType | ''>('');
const ingredients = ref<Ingredient[]>([]);
const steps = ref<Step[]>([]);
const ready = ref(!isSaved);

function ingredient(food: { food_id: string; name: string }, grams: number, extra: Partial<Ingredient> = {}): Ingredient {
    return { key: nextKey++, food_id: food.food_id, name: food.name, grams: String(grams), isCooked: false, canToggleCooked: false, amountText: '', ...extra };
}

function stepsOf(texts: unknown[]): Step[] {
    return texts.map((text) => ({ key: nextKey++, text: String(text) }));
}

// The form starts from the recipe once it has loaded, and is not reset by a later read.
watch(
    data,
    (recipe) => {
        if (!recipe || ready.value) return;
        id.value = recipe.recipe_id;
        name.value = recipe.name;
        servings.value = String(recipe.servings);
        mealType.value = isMealType(recipe.meal_type) ? recipe.meal_type : '';
        ingredients.value = recipe.ingredients.map((item) =>
            ingredient(item, item.grams, { isCooked: item.is_cooked, canToggleCooked: item.can_toggle_cooked, amountText: item.amount_text }),
        );
        steps.value = stepsOf(recipe.steps);
        ready.value = true;
    },
    { immediate: true },
);

if (props.draft) {
    name.value = props.draft.name;
    servings.value = String(props.draft.servings);
    mealType.value = isMealType(props.draft.meal_type) ? props.draft.meal_type : '';
    ingredients.value = props.draft.ingredients.map((item) => ingredient(item, item.grams));
    steps.value = stepsOf(props.draft.steps);
}

// A saved recipe's form shows only while its copy is on screen: a read that takes the copy
// away (signed out, or the recipe gone) shows why instead (OFF-003).
const shown = computed(() => ready.value && (!isSaved || data.value !== undefined));
const readOnly = computed(() => data.value?.is_read_only === true);
const title = computed(() => data.value?.name ?? (isSaved ? 'Recipe' : 'New recipe'));

// Foods picked in the Pantry join at 100 g each. A food already in the recipe stays as it is.
const picking = ref(false);
function addFoods(foods: PickedFood[]): void {
    for (const food of foods) {
        if (!ingredients.value.some((item) => item.food_id === food.food_id)) ingredients.value.push(ingredient(food, 100));
    }
    picking.value = false;
}

function removeIngredient(key: number): void {
    ingredients.value = ingredients.value.filter((item) => item.key !== key);
}

function addStep(): void {
    steps.value.push({ key: nextKey++, text: '' });
}

function removeStep(key: number): void {
    steps.value = steps.value.filter((step) => step.key !== key);
}

// What Save sends, or null while a name, a number of servings, a meal type or an ingredient
// with an amount is missing. Blank steps are not saved.
const input = computed<RecipeSaveInput | null>(() => {
    const count = typedNumber(servings.value);
    const items = ingredients.value.map((item) => ({ food_id: item.food_id, grams: typedNumber(item.grams), is_cooked: item.isCooked }));
    if (name.value.trim() === '' || count === null || mealType.value === '' || items.length === 0 || items.some((item) => item.grams === null)) return null;

    return {
        id: id.value,
        name: name.value.trim(),
        servings: count,
        meal_type: mealType.value,
        ingredients: items as RecipeSaveInput['ingredients'],
        steps: steps.value.map((step) => step.text.trim()).filter((text) => text !== ''),
    };
});

const saving = useWrite(load);
// The recipe a delete removed is not read again: the screen goes back.
const deleting = useWrite(noRead);
const confirmingDelete = ref(false);
const busy = computed(() => saving.busy.value || deleting.busy.value);

async function save(): Promise<void> {
    if (!input.value) return;
    if ((await saving.send('recipe_save', { ...input.value }, "Couldn't save the recipe. Try again.")) === 'done') router.back();
}

async function remove(): Promise<void> {
    const input: RecipeDeleteInput = { recipe_id: id.value };
    if ((await deleting.send('recipe_delete', { ...input }, "Couldn't delete the recipe. Try again.")) === 'done') {
        confirmingDelete.value = false;
        router.back();
    }
}
</script>

<template>
    <main class="bb-screen-content flex flex-col gap-4">
        <header class="grid grid-cols-[1fr_auto_1fr] items-center gap-2">
            <button v-if="draft" type="button" data-test="back" class="min-h-tap justify-self-start text-accent" :disabled="busy" @click="emit('back')">Back</button>
            <button v-else-if="!isSaved" type="button" data-test="cancel" class="min-h-tap justify-self-start text-accent" :disabled="busy" @click="router.back()">Cancel</button>
            <span v-else />
            <h1 class="truncate text-center text-base font-semibold">{{ title }}</h1>
            <button
                v-if="shown && !readOnly"
                type="button"
                data-test="save"
                class="min-h-tap justify-self-end font-semibold text-accent disabled:opacity-40"
                :disabled="input === null || busy"
                @click="save"
            >
                {{ saving.busy.value ? 'Saving…' : 'Save' }}
            </button>
        </header>

        <p v-if="saving.error.value" role="alert" class="text-sm text-tone-warning">{{ saving.error.value }}</p>

        <template v-if="shown">
            <!-- RCP-020: a library recipe is read, not changed. -->
            <section v-if="readOnly && data" class="flex flex-col gap-2" aria-label="Details">
                <StatusPill text="Library recipe" tone="info" class="self-start" />
                <div class="divide-y divide-secondary-text/15 rounded-2xl bg-surface px-4">
                    <p class="flex min-h-tap items-center justify-between gap-2">
                        <span>Servings</span>
                        <span class="text-secondary-text">{{ data.servings_text }}</span>
                    </p>
                    <p v-if="data.meal_type_title" class="flex min-h-tap items-center justify-between gap-2">
                        <span>Meal type</span>
                        <span class="text-secondary-text">{{ data.meal_type_title }}</span>
                    </p>
                </div>
            </section>
            <section v-else class="divide-y divide-secondary-text/15 rounded-2xl bg-surface px-4" aria-label="Details">
                <input
                    v-model="name"
                    type="text"
                    aria-label="Name"
                    placeholder="Recipe name"
                    autocapitalize="sentences"
                    autocomplete="off"
                    maxlength="255"
                    :disabled="busy"
                    class="min-h-tap w-full bg-transparent text-primary-text placeholder:text-secondary-text focus:outline-none"
                />
                <label class="flex min-h-tap items-center justify-between gap-2">
                    <span>Servings</span>
                    <input
                        v-model="servings"
                        type="text"
                        inputmode="decimal"
                        aria-label="Servings"
                        :disabled="busy"
                        class="h-9 w-20 rounded-lg bg-background px-2 text-right tabular-nums"
                    />
                </label>
                <label class="flex min-h-tap items-center justify-between gap-2">
                    <span>Meal type</span>
                    <select v-model="mealType" aria-label="Meal type" :disabled="busy" class="min-h-tap bg-transparent text-right text-accent">
                        <option value="" disabled>Choose</option>
                        <option v-for="option in MEAL_TYPES" :key="option.value" :value="option.value">{{ option.title }}</option>
                    </select>
                </label>
            </section>

            <section v-if="isSaved && data" data-test="per-serving" aria-labelledby="recipe-per-serving">
                <h2 id="recipe-per-serving" class="pb-1 text-lg font-semibold">Per serving</h2>
                <p class="rounded-2xl bg-surface px-4 py-3 tabular-nums">
                    <PendingMark v-if="pending" derived>{{ data.per_serving_text }}</PendingMark>
                    <template v-else>{{ data.per_serving_text }}</template>
                </p>
            </section>

            <section aria-labelledby="recipe-ingredients">
                <h2 id="recipe-ingredients" class="pb-1 text-lg font-semibold">Ingredients</h2>
                <ul class="divide-y divide-secondary-text/15 rounded-2xl bg-surface px-4">
                    <li v-for="item in ingredients" :key="item.key" data-test="ingredient" class="flex flex-col gap-1.5 py-2">
                        <template v-if="readOnly">
                            <span class="flex min-h-9 items-center justify-between gap-2">
                                <span>{{ item.name }}</span>
                                <span class="text-sm text-secondary-text tabular-nums">{{ item.amountText }}</span>
                            </span>
                        </template>
                        <template v-else>
                            <span class="flex items-center gap-2">
                                <span class="min-w-0 flex-1">{{ item.name }}</span>
                                <input
                                    v-model="item.grams"
                                    type="text"
                                    inputmode="decimal"
                                    placeholder="0"
                                    :aria-label="`${item.name} grams`"
                                    :disabled="busy"
                                    class="h-9 w-18 rounded-lg bg-background px-2 text-right tabular-nums"
                                />
                                <span class="text-secondary-text">g</span>
                                <button
                                    type="button"
                                    class="flex min-h-tap min-w-tap items-center justify-center text-secondary-text"
                                    :aria-label="`Remove ${item.name}`"
                                    :disabled="busy"
                                    @click="removeIngredient(item.key)"
                                >
                                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-6">
                                        <circle cx="12" cy="12" r="10" fill="currentColor" />
                                        <path d="M7.5 12h9" stroke="var(--color-surface)" stroke-width="2.2" stroke-linecap="round" />
                                    </svg>
                                </button>
                            </span>
                            <!-- FOOD-006: only a food with a cooked value can be weighed cooked. -->
                            <SegmentedPicker
                                v-if="item.canToggleCooked"
                                :model-value="item.isCooked ? 'cooked' : 'raw'"
                                :label="`${item.name} weighed`"
                                :options="WEIGHINGS"
                                :disabled="busy"
                                class="w-48 bg-secondary-text/15!"
                                @update:model-value="(weighing) => (item.isCooked = weighing === 'cooked')"
                            />
                        </template>
                    </li>
                    <li v-if="!readOnly">
                        <button type="button" data-test="add-ingredient" class="flex min-h-tap items-center gap-2 font-medium text-accent" :disabled="busy" @click="picking = true">
                            <svg aria-hidden="true" viewBox="0 0 24 24" class="size-6"><circle cx="12" cy="12" r="10" fill="currentColor" /><path d="M12 7.5v9M7.5 12h9" stroke="var(--color-surface)" stroke-width="2.2" stroke-linecap="round" /></svg>
                            Add ingredient
                        </button>
                    </li>
                    <li v-if="readOnly && ingredients.length === 0" class="flex min-h-tap items-center text-secondary-text">No ingredients.</li>
                </ul>
            </section>

            <section aria-labelledby="recipe-steps">
                <h2 id="recipe-steps" class="pb-1 text-lg font-semibold">Steps</h2>
                <ol class="divide-y divide-secondary-text/15 rounded-2xl bg-surface px-4">
                    <li v-for="(step, index) in steps" :key="step.key" class="flex items-baseline gap-2.5 py-2">
                        <span class="text-sm text-secondary-text tabular-nums">{{ index + 1 }}.</span>
                        <span v-if="readOnly" class="flex-1">{{ step.text }}</span>
                        <template v-else>
                            <textarea
                                v-model="step.text"
                                rows="1"
                                placeholder="Step"
                                :aria-label="`Step ${index + 1}`"
                                :disabled="busy"
                                class="field-sizing-content min-h-9 flex-1 resize-none bg-transparent focus:outline-none"
                            />
                            <button
                                type="button"
                                class="flex min-h-tap min-w-tap items-center justify-center self-center text-secondary-text"
                                :aria-label="`Remove step ${index + 1}`"
                                :disabled="busy"
                                @click="removeStep(step.key)"
                            >
                                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-6">
                                    <circle cx="12" cy="12" r="10" fill="currentColor" />
                                    <path d="M7.5 12h9" stroke="var(--color-surface)" stroke-width="2.2" stroke-linecap="round" />
                                </svg>
                            </button>
                        </template>
                    </li>
                    <li v-if="!readOnly">
                        <button type="button" data-test="add-step" class="flex min-h-tap items-center gap-2 font-medium text-accent" :disabled="busy" @click="addStep">
                            <svg aria-hidden="true" viewBox="0 0 24 24" class="size-6"><circle cx="12" cy="12" r="10" fill="currentColor" /><path d="M12 7.5v9M7.5 12h9" stroke="var(--color-surface)" stroke-width="2.2" stroke-linecap="round" /></svg>
                            Add step
                        </button>
                    </li>
                    <li v-if="readOnly && steps.length === 0" class="flex min-h-tap items-center text-secondary-text">No steps.</li>
                </ol>
            </section>

            <button
                v-if="data?.can_delete && !readOnly"
                type="button"
                data-test="delete"
                class="min-h-tap rounded-2xl bg-surface font-medium text-tone-warning"
                :disabled="busy"
                @click="(deleting.error.value = null), (confirmingDelete = true)"
            >
                Delete recipe
            </button>
        </template>
        <ReadState v-else :failure="failure" what="The recipe" @retry="load" />

        <BottomSheet :open="picking" title="Add ingredient" :save-title="null" @cancel="picking = false">
            <PantryView purpose="recipeIngredients" @picked="addFoods" @cancel="picking = false" />
        </BottomSheet>

        <BottomSheet
            :open="confirmingDelete"
            :title="`Delete ${title}?`"
            :save-title="null"
            :heights="['half']"
            :cancel-disabled="deleting.busy.value"
            :dismissible="!deleting.busy.value"
            :error="deleting.error.value"
            @cancel="confirmingDelete = false"
        >
            <div class="flex flex-col gap-4 p-4">
                <p class="text-secondary-text">Meals that already have it keep their copy.</p>
                <button
                    type="button"
                    data-test="confirm-delete"
                    class="min-h-tap rounded-2xl bg-tone-warning-bg font-semibold text-tone-warning"
                    :disabled="deleting.busy.value"
                    @click="remove"
                >
                    Delete recipe
                </button>
            </div>
        </BottomSheet>
    </main>
</template>
