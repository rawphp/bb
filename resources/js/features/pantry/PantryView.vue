<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import type { DietFiltersSetInput } from '@/contracts/diet_filters_set';
import type { FoodFavoriteSetInput } from '@/contracts/food_favorite_set';
import type { FoodSearchInput, FoodSearchOutput } from '@/contracts/food_search';
import type { MealFoodAddInput } from '@/contracts/meal_food_add';
import type { MealFoodAddShowInput, MealFoodAddShowOutput } from '@/contracts/meal_food_add_show';
import type { MealSavedMealLoadInput } from '@/contracts/meal_saved_meal_load';
import { readNow } from '@/app/call';
import ReadState from '@/app/ReadState.vue';
import { screenRead, type ReadFailure } from '@/app/screenRead';
import { onScreenReturn } from '@/app/screenTop';
import { ulid } from '@/app/ulid';
import { useWrite } from '@/app/useWrite';
import MacroBar from '@/design/MacroBar.vue';
import StatusPill from '@/design/StatusPill.vue';
import PantryFilterBar from './PantryFilterBar.vue';
import PantryRowView, { type Weighing } from './PantryRowView.vue';
import { CONFIRM_TITLES, TABS, type PantryCollection, type PantryGroup, type PantryPurpose, type PantrySource, type PickedFood, type PantryTab, type PantryUnit } from './options';

type Food = FoodSearchOutput['sections'][number]['rows'][number];
type Diet = FoodSearchOutput['diets'][number];

/** What Confirm hands to the meal (FOOD-028). A pick's entry id is made once, so a retry adds nothing twice (CAP-019). */
type Pick =
    | { kind: 'food'; key: string; entryId: string; id: string; name: string; brand: string | null; hasCooked: boolean }
    | { kind: 'recipe'; key: string; entryId: string; id: string; name: string }
    | { kind: 'saved_meal'; key: string; id: string; name: string };

// The Pantry, the food picker (FOOD-020 to FOOD-028), ported from rp-clone's PantryView:
// tabs, search, filters, and a basket that is added to the meal all at once. Every list
// and its words come from food_search; the script holds what the user has typed and
// picked. With a meal it adds the basket to that meal and goes back. Without one it is the
// Nutrition tab's browser (NAV-001): favorites and filters, nothing to add to.
// For a recipe (RCP-001, RCP-003, rp-clone's PantryPurpose) it picks foods only and hands
// them back: several for the recipe's ingredients, or one to match a drafted item.
// The basket stands at the foot beside Add. For a meal it asks meal_food_add_show what Add
// would make of the picks (FOOD-030): each one's amount and the meal's bars after it.
const props = withDefaults(
    defineProps<{
        payload?: FoodSearchOutput;
        /** The meal the basket is for. */
        mealId?: string;
        /** The text typed in the meal screen's search field (UI-021). */
        search?: string;
        /** The curated group of a short-macro hint (MEAL-007). */
        group?: PantryGroup | null;
        /** A write that changes these numbers waits in the outbox (OFF-008). */
        pending?: boolean;
        /** What the picks are for: a meal (or, with no meal, browsing), or a recipe. */
        purpose?: PantryPurpose;
    }>(),
    { payload: undefined, mealId: undefined, search: '', group: null, pending: false, purpose: 'meal' },
);

const emit = defineEmits<{ picked: [foods: PickedFood[]]; cancel: [] }>();

const router = useRouter();

const query = ref(props.search);
const tab = ref<PantryTab>('curated');
const collection = ref<PantryCollection | null>(null);
const source = ref<PantrySource | null>(null);
const groupFilter = ref<PantryGroup | null>(props.group);
const unit = ref<PantryUnit>('g');
const expandedGroups = ref<PantryGroup[]>([]);
const basket = ref<Pick[]>([]);
const weighings = ref<Record<string, Weighing>>({});

const forRecipe = computed(() => props.purpose !== 'meal');
const forMeal = computed(() => !forRecipe.value && props.mealId !== undefined);
/** Rows pick into the basket, and Confirm hands it on. */
const picking = computed(() => forMeal.value || forRecipe.value);
const destination = computed(() => (forRecipe.value ? 'recipe' : 'meal'));

function input() {
    return {
        query: query.value,
        tab: tab.value,
        collection: collection.value,
        source: source.value,
        group: groupFilter.value,
        unit: unit.value,
        meal_id: forMeal.value ? props.mealId! : null,
        expanded_groups: expandedGroups.value,
    } satisfies FoodSearchInput;
}

const data = ref<FoodSearchOutput | undefined>(props.payload);
const failure = ref<ReadFailure | null>(null);
// A search that did not load, said over the foods still shown.
const searchError = ref<string | null>(null);

const read = screenRead<FoodSearchOutput>(
    'food_search',
    'The foods could not be loaded.',
    (fresh) => {
        failure.value = null;
        searchError.value = null;
        data.value = fresh;
    },
    (why, hadData) => {
        // The last search's foods may stay, unless this load showed a copy that must go
        // (OFF-003) or the user is signed out.
        if (data.value && !hadData && !why.signedOut) {
            searchError.value = why.offline ? 'Search needs a connection.' : why.error;
            return;
        }
        data.value = undefined;
        searchError.value = null;
        failure.value = why;
    },
);

function load(options: { fresh?: boolean } = {}): Promise<void> {
    return read(input(), options);
}

// Typing searches once the user pauses; every other change reads at once.
let typing: ReturnType<typeof setTimeout> | undefined;
watch(query, () => {
    clearTimeout(typing);
    typing = setTimeout(() => void load(), 250);
});
watch([tab, collection, source, groupFilter, unit, expandedGroups], () => void load(), { deep: true });

onMounted(() => {
    if (!data.value) void load();
});
onUnmounted(() => clearTimeout(typing));
// Back on top after a screen it opened (a recipe made from its Recipes list, say), it reads
// again, so what that screen saved shows here.
onScreenReturn(() => {
    if (props.payload === undefined) void load();
});

const favorites = useWrite(load);
const diets = useWrite(load);
const adding = useWrite(load);

function toggleFavorite(food: Food): void {
    void favorites.send('food_favorite_set', { food_id: food.food_id, favorite: !food.is_favorite } satisfies FoodFavoriteSetInput, "Couldn't change your favorites. Try again.");
}

function toggleDiet(key: Diet['key']): void {
    const on = (data.value?.diets ?? []).filter((diet) => (diet.key === key ? !diet.is_on : diet.is_on));
    void diets.send('diet_filters_set', { filters: on.map((diet) => ({ filter: diet.key })) } satisfies DietFiltersSetInput, "Couldn't save your diet filters. Try again.");
}

// FOOD-006: a food weighed cooked shows, and is added, by its cooked value.
function weighingOf(foodId: string, hasCooked: boolean): Weighing {
    return hasCooked ? (weighings.value[foodId] ?? 'raw') : 'raw';
}

function isPicked(key: string): boolean {
    return basket.value.some((pick) => pick.key === key);
}

function toggle(pick: Pick): void {
    if (isPicked(pick.key)) remove(pick.key);
    else basket.value.push(pick);
}

function remove(key: string): void {
    basket.value = basket.value.filter((pick) => pick.key !== key);
}

function toggleFood(food: Food): void {
    const key = `food-${food.food_id}`;
    // A drafted item takes one food: a new pick replaces the old one.
    if (props.purpose === 'recipeMatch' && !isPicked(key)) basket.value = [];
    toggle({ kind: 'food', key, entryId: ulid(), id: food.food_id, name: food.name, brand: food.detail ?? null, hasCooked: food.cooked != null });
}

function toggleRecipe(recipe: FoodSearchOutput['recipes'][number]): void {
    toggle({ kind: 'recipe', key: `recipe-${recipe.recipe_id}`, entryId: ulid(), id: recipe.recipe_id, name: recipe.name });
}

function toggleSavedMeal(meal: FoodSearchOutput['saved_meals'][number]): void {
    toggle({ kind: 'saved_meal', key: `saved-meal-${meal.saved_meal_id}`, id: meal.saved_meal_id, name: meal.name });
}

function pickDetail(pick: Pick): string | null {
    if (pick.kind === 'recipe') return 'Recipe';
    if (pick.kind === 'saved_meal') return 'Saved meal';
    // A recipe takes the food, not how it was weighed here.
    if (!pick.hasCooked || forRecipe.value) return pick.brand;
    return weighingOf(pick.id, true) === 'cooked' ? 'Cooked' : 'Raw';
}

function pickSuggestion(suggestion: NonNullable<FoodSearchOutput['suggestion']>): void {
    query.value = suggestion.query;
}

function showMore(group: PantryGroup): void {
    if (!expandedGroups.value.includes(group)) expandedGroups.value = [...expandedGroups.value, group];
}

// Add sends every pick the user confirmed, even after a swipe back, another tab or a
// pushed screen took the Pantry off screen (FOOD-028). It goes back only while the
// Pantry is still the screen on show.
let gone = false;
onUnmounted(() => (gone = true));

// FOOD-028: the foods and recipes go in one write; each saved meal loads on its own.
// Each pick leaves the basket once its write is done, so a retry sends only the rest.
// The basket cannot change meanwhile: the screen is inert while Add runs.
/** A food or recipe pick as meal_food_add takes it. */
function entryOf(pick: Exclude<Pick, { kind: 'saved_meal' }>): MealFoodAddInput['entries'][number] {
    return pick.kind === 'food' ? { id: pick.entryId, food_id: pick.id, weighing: weighingOf(pick.id, pick.hasCooked) } : { id: pick.entryId, recipe_id: pick.id };
}

function sentPicks(): Exclude<Pick, { kind: 'saved_meal' }>[] {
    return basket.value.filter((pick): pick is Exclude<Pick, { kind: 'saved_meal' }> => pick.kind !== 'saved_meal');
}

// FOOD-030: what Add would make of the basket. Each change asks again; only the latest answer
// shows. Offline, or with no answer, the basket lists the picks alone.
const preview = ref<MealFoodAddShowOutput | null>(null);
let asked = 0;
const previewInput = computed<MealFoodAddShowInput | null>(() => {
    if (!forMeal.value) return null;
    const entries = sentPicks().map(entryOf);
    return entries.length === 0 ? null : ({ meal_id: props.mealId!, entries: entries as MealFoodAddShowInput['entries'] } satisfies MealFoodAddShowInput);
});
watch(
    () => JSON.stringify(previewInput.value),
    () => {
        const input = previewInput.value;
        const ask = ++asked;
        if (input === null) {
            preview.value = null;
            return;
        }
        readNow<MealFoodAddShowOutput>('meal_food_add_show', { ...input }).then(
            (answer) => {
                if (ask === asked) preview.value = answer;
            },
            () => {
                if (ask === asked) preview.value = null;
            },
        );
    },
);
// A saved meal loads on its own write, so the fit cannot say what the meal is after it.
const fitShown = computed(() => preview.value !== null && !basket.value.some((pick) => pick.kind === 'saved_meal'));
const hint = computed(() => (fitShown.value ? (preview.value!.hints[0] ?? null) : null));

function amountOf(pick: Pick): string | null {
    if (pick.kind === 'saved_meal' || !fitShown.value) return null;
    return preview.value?.entries.find((entry) => entry.id === pick.entryId)?.amount_text ?? null;
}

function pickLine(pick: Pick): string {
    return [pickDetail(pick), amountOf(pick)].filter((part) => part).join(' · ');
}

async function confirm(): Promise<void> {
    if (forRecipe.value) {
        if (basket.value.length > 0) emit('picked', basket.value.map((pick) => ({ food_id: pick.id, name: pick.name })));
        return;
    }
    if (!props.mealId || basket.value.length === 0) return;
    const here = router.currentRoute.value.fullPath;
    const shown = () => !gone && router.currentRoute.value.fullPath === here;
    const sent = sentPicks();
    const entries = sent.map(entryOf);
    if (entries.length > 0) {
        const input = { meal_id: props.mealId, entries: entries as MealFoodAddInput['entries'] } satisfies MealFoodAddInput;
        if ((await adding.send('meal_food_add', input, "Couldn't add the foods. Try again.")) !== 'done') return;
        basket.value = basket.value.filter((pick) => !sent.some((done) => done.key === pick.key));
    }
    for (const pick of basket.value.filter((pick) => pick.kind === 'saved_meal')) {
        const input = { meal_id: props.mealId, saved_meal_id: pick.id } satisfies MealSavedMealLoadInput;
        if ((await adding.send('meal_saved_meal_load', input, "Couldn't add the saved meal. Try again.")) !== 'done') return;
        remove(pick.key);
    }
    if (shown()) router.back();
}

function cancel(): void {
    if (forRecipe.value) emit('cancel');
    else router.back();
}

const confirmTitle = computed(() => CONFIRM_TITLES[props.purpose](basket.value.length));
const error = computed(() => favorites.error.value ?? diets.error.value ?? searchError.value);
</script>

<template>
    <main class="bb-screen-content flex min-h-full flex-col gap-3 pb-0">
        <!-- FOOD-028: while Add runs nothing here can change what it sends, or leave the screen. -->
        <div data-test="pantry-content" class="flex flex-col gap-3" :inert="adding.busy.value || undefined">
        <header class="grid grid-cols-[1fr_auto_1fr] items-center">
            <button type="button" data-test="cancel" class="min-h-tap justify-self-start rounded-full bg-surface px-4 text-primary-text" @click="cancel">
                {{ picking ? 'Cancel' : 'Done' }}
            </button>
            <h1 class="text-base font-semibold">Pantry</h1>
        </header>

        <label class="flex min-h-tap items-center gap-2 rounded-full bg-surface px-4">
            <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5 shrink-0 text-secondary-text" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                <circle cx="11" cy="11" r="7" />
                <path d="m16.5 16.5 4 4" />
            </svg>
            <input
                v-model="query"
                type="search"
                aria-label="Search foods"
                placeholder="Search foods"
                maxlength="100"
                enterkeyhint="search"
                autocomplete="off"
                class="min-h-tap w-full bg-transparent text-primary-text placeholder:text-secondary-text focus:outline-none"
            />
        </label>

        <div v-if="collection === null" role="tablist" aria-label="Foods" class="grid grid-cols-4 rounded-full bg-secondary-text/15 p-0.5">
            <button
                v-for="option in TABS"
                :key="option.value"
                type="button"
                role="tab"
                :aria-selected="tab === option.value"
                class="min-h-9 rounded-full text-sm font-medium"
                :class="tab === option.value ? 'bg-surface text-primary-text shadow-sm ring-1 ring-secondary-text/25' : 'text-secondary-text'"
                @click="tab = option.value"
            >
                {{ option.title }}
            </button>
        </div>

        <PantryFilterBar
            v-model:collection="collection"
            v-model:source="source"
            v-model:group="groupFilter"
            v-model:unit="unit"
            :diets="data?.diets ?? []"
            :shows-collections="!forRecipe"
            :diet-saving="diets.busy.value"
            :diet-error="diets.error.value"
            @diet="toggleDiet"
        />

        <p v-if="error" role="alert" data-test="search-error" class="text-sm text-tone-warning">{{ error }}</p>
        <p v-if="collection === null && source !== null && query.trim() === ''" class="text-sm text-secondary-text">The source filter narrows search results. Type to search.</p>

        <template v-if="data">
            <section v-if="!forRecipe && data.already_in_meal.length > 0" data-test="already-in-meal" aria-labelledby="pantry-in-meal">
                <h2 id="pantry-in-meal" class="pb-1 text-lg font-semibold">Already in meal</h2>
                <ul class="divide-y divide-secondary-text/15">
                    <li v-for="food in data.already_in_meal" :key="food.food_id" class="flex min-h-tap items-center gap-3 text-secondary-text">
                        <svg aria-hidden="true" viewBox="0 0 24 24" class="size-6 shrink-0" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10" />
                            <path d="m8 12.5 2.5 2.5 5.5-6" />
                        </svg>
                        {{ food.name }}
                    </li>
                </ul>
            </section>

            <template v-if="collection === 'recipes'">
                <div class="grid grid-cols-2 gap-2">
                    <RouterLink
                        :to="{ path: '/nutrition/recipes/generate', query: forMeal ? { meal: mealId } : {} }"
                        class="flex min-h-tap items-center justify-center rounded-xl bg-accent/10 px-2 text-sm font-semibold text-accent"
                    >
                        Generate with AI
                    </RouterLink>
                    <RouterLink to="/nutrition/recipes/import" class="flex min-h-tap items-center justify-center rounded-xl bg-accent/10 px-2 text-sm font-semibold text-accent">
                        Import from URL
                    </RouterLink>
                </div>
                <ul class="divide-y divide-secondary-text/15">
                    <li v-for="recipe in data.recipes" :key="recipe.recipe_id">
                        <component
                            :is="forMeal ? 'button' : 'div'"
                            :type="forMeal ? 'button' : undefined"
                            data-test="collection-row"
                            :aria-pressed="forMeal ? isPicked(`recipe-${recipe.recipe_id}`) : undefined"
                            class="flex min-h-tap w-full items-center gap-3 py-2 text-left"
                            @click="forMeal && toggleRecipe(recipe)"
                        >
                            <span class="min-w-0 flex-1">
                                <span class="block text-primary-text">{{ recipe.name }}</span>
                                <span class="block text-sm text-secondary-text">{{ recipe.meal_type_title ? `${recipe.meal_type_title} · ${recipe.macros_text}` : recipe.macros_text }}</span>
                            </span>
                            <span v-if="forMeal" aria-hidden="true" class="size-7 shrink-0 rounded-full border-[1.6px]" :class="isPicked(`recipe-${recipe.recipe_id}`) ? 'border-accent bg-accent' : 'border-secondary-text'" />
                        </component>
                    </li>
                </ul>
            </template>

            <ul v-else-if="collection === 'saved_meals'" class="divide-y divide-secondary-text/15">
                <li v-for="meal in data.saved_meals" :key="meal.saved_meal_id">
                    <component
                        :is="forMeal ? 'button' : 'div'"
                        :type="forMeal ? 'button' : undefined"
                        data-test="collection-row"
                        :aria-pressed="forMeal ? isPicked(`saved-meal-${meal.saved_meal_id}`) : undefined"
                        class="flex min-h-tap w-full items-center gap-3 py-2 text-left"
                        @click="forMeal && toggleSavedMeal(meal)"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="block text-primary-text">{{ meal.name }}</span>
                            <span class="block text-sm text-secondary-text">{{ meal.detail }}</span>
                        </span>
                        <span v-if="forMeal" aria-hidden="true" class="size-7 shrink-0 rounded-full border-[1.6px]" :class="isPicked(`saved-meal-${meal.saved_meal_id}`) ? 'border-accent bg-accent' : 'border-secondary-text'" />
                    </component>
                </li>
            </ul>

            <template v-else>
                <button v-if="data.suggestion" type="button" data-test="suggestion" class="min-h-tap self-start text-sm font-semibold text-accent" @click="pickSuggestion(data.suggestion)">
                    {{ data.suggestion.text }}
                </button>
                <section v-for="section in data.sections" :key="section.id" data-test="food-section" :aria-label="section.title ?? 'Matches'">
                    <h2 v-if="section.title" class="pb-1 text-lg font-semibold">{{ section.title }}</h2>
                    <ul class="divide-y divide-secondary-text/15">
                        <PantryRowView
                            v-for="food in section.rows"
                            :key="food.food_id"
                            :row="food"
                            :weighing="weighingOf(food.food_id, food.cooked != null)"
                            :picked="isPicked(`food-${food.food_id}`)"
                            :pickable="picking"
                            :destination="destination"
                            :weighs="!forRecipe"
                            :pending="pending"
                            :favorite-disabled="favorites.busy.value"
                            @pick="toggleFood(food)"
                            @favorite="toggleFavorite(food)"
                            @weighing="(weighing) => (weighings[food.food_id] = weighing)"
                        />
                    </ul>
                    <button
                        v-if="section.show_more_title && section.group"
                        type="button"
                        data-test="show-more"
                        class="min-h-tap text-sm font-semibold text-accent"
                        :aria-label="`${section.show_more_title} in ${section.title}`"
                        @click="showMore(section.group as PantryGroup)"
                    >
                        {{ section.show_more_title }}
                    </button>
                </section>
            </template>

            <p v-if="data.empty_text" data-test="empty" class="text-secondary-text">{{ data.empty_text }}</p>
            <!-- FOOD-062: after the empty note, as rp-clone puts it. -->
            <RouterLink
                v-if="!forRecipe && collection === null && data.create_custom_food"
                :to="{ path: '/nutrition/custom-foods/new', query: { name: data.create_custom_food.name } }"
                class="flex min-h-tap items-center self-start text-sm font-semibold text-accent"
            >
                {{ data.create_custom_food.title }}
            </RouterLink>
        </template>
        <ReadState v-else :failure="failure" what="The Pantry" @retry="load" />

        </div>

        <div v-if="picking" class="sticky bottom-0 -mx-4 mt-auto bg-background/90 px-4 py-2 backdrop-blur">
            <!-- FOOD-028, FOOD-030: the basket stays in view while the foods scroll, and waits with the rest while Add runs. -->
            <section v-if="basket.length > 0" data-test="basket" aria-labelledby="pantry-basket" class="flex flex-col gap-2 pb-2" :inert="adding.busy.value || undefined">
                <div class="flex min-h-7 items-center justify-between gap-2">
                    <h2 id="pantry-basket" class="text-base font-semibold">Adding to {{ destination }}</h2>
                    <StatusPill v-if="fitShown && preview!.targets_met" :text="preview!.targets_met.text" :tone="preview!.targets_met.tone" />
                </div>
                <TransitionGroup
                    tag="ul"
                    class="-mx-4 flex gap-2 overflow-x-auto px-4 [scrollbar-width:none] md:mx-0 md:flex-wrap md:px-0"
                    enter-active-class="transition-transform duration-200 ease-[cubic-bezier(0.2,1.4,0.4,1)] motion-reduce:transition-opacity"
                    enter-from-class="scale-75 motion-reduce:scale-100 motion-reduce:opacity-0"
                >
                    <li v-for="pick in basket" :key="pick.key" class="flex min-h-tap max-w-[18rem] shrink-0 items-center gap-1 rounded-2xl bg-surface py-1 pl-3 shadow-sm ring-1 ring-secondary-text/15">
                        <span class="min-w-0 flex-1 leading-tight">
                            <span class="block truncate text-sm font-medium text-primary-text">{{ pick.name }}</span>
                            <span v-if="pickLine(pick)" data-test="basket-amount" class="block truncate text-xs text-secondary-text tabular-nums">{{ pickLine(pick) }}</span>
                        </span>
                        <button
                            type="button"
                            class="flex min-h-tap min-w-tap shrink-0 items-center justify-center text-secondary-text"
                            :aria-label="`Remove ${pick.name}`"
                            @click="remove(pick.key)"
                        >
                            <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5">
                                <circle cx="12" cy="12" r="10" fill="currentColor" />
                                <path d="M7.5 12h9" stroke="var(--color-background)" stroke-width="2.2" stroke-linecap="round" />
                            </svg>
                        </button>
                    </li>
                </TransitionGroup>
                <div v-if="fitShown" data-test="basket-bars" class="grid grid-cols-2 gap-x-4 gap-y-1 md:grid-cols-4">
                    <MacroBar
                        v-for="bar in preview!.bars"
                        :key="bar.label"
                        :bar="bar"
                    />
                </div>
                <button v-if="hint" type="button" data-test="basket-hint" class="flex min-h-tap items-center self-start text-left text-sm font-medium text-accent" @click="groupFilter = hint.group as PantryGroup">
                    {{ hint.text }}
                </button>
            </section>
            <p v-if="adding.error.value" role="alert" data-test="confirm-error" class="pb-2 text-sm text-tone-warning">{{ adding.error.value }}</p>
            <button
                type="button"
                data-test="confirm"
                class="min-h-tap w-full rounded-full bg-accent font-semibold text-on-accent disabled:opacity-40"
                :disabled="basket.length === 0 || adding.busy.value"
                @click="confirm"
            >
                {{ confirmTitle }}
            </button>
        </div>
    </main>
</template>
