import type { FoodSearchInput } from '@/contracts/food_search';

// The Pantry's fixed choices and their words, as rp-clone's PantryTypes names them. The
// values are food_search's input; the server sends the results.

export type PantryTab = NonNullable<FoodSearchInput['tab']>;
export type PantryUnit = NonNullable<FoodSearchInput['unit']>;

/** What the Pantry is open for (rp-clone's PantryPurpose): a meal, or with no meal browsing; a recipe's ingredients; one drafted item's food. */
export type PantryPurpose = 'meal' | 'recipeIngredients' | 'recipeMatch';

/** A food picked for a recipe, as Confirm hands it back. */
export type PickedFood = { food_id: string; name: string };

/** The confirm button for a number of picks, in PantryPurpose's words. */
export const CONFIRM_TITLES: Record<PantryPurpose, (count: number) => string> = {
    meal: (count) => (count === 0 ? 'Add to meal' : `Add ${count} to meal`),
    recipeIngredients: (count) => (count === 0 ? 'Add to recipe' : `Add ${count} to recipe`),
    recipeMatch: (count) => (count === 0 ? 'Use food' : 'Use selected food'),
};

export const TABS: { value: PantryTab; title: string }[] = [
    { value: 'curated', title: 'Curated' },
    { value: 'recents', title: 'Recents' },
    { value: 'favorites', title: 'Favorites' },
    { value: 'custom', title: 'Custom' },
];

/** FOOD-020: Recipes and Saved Meals show in place of the foods. */
export const COLLECTIONS = [
    { value: 'recipes', title: 'Recipes' },
    { value: 'saved_meals', title: 'Saved Meals' },
] as const;

/** FOOD-025. */
export const SOURCES = [
    { value: 'recents', title: 'Recents' },
    { value: 'favorites', title: 'Favorites' },
    { value: 'curated', title: 'Curated' },
    { value: 'custom', title: 'Custom' },
    { value: 'restaurants', title: 'Restaurants' },
    { value: 'packaged', title: 'Packaged' },
] as const;

/** FOOD-026: the FOOD-003 groups. */
export const GROUPS = [
    { value: 'protein_recommended', title: 'Protein · Recommended' },
    { value: 'protein_sparingly', title: 'Protein · Use sparingly' },
    { value: 'protein_before_bed', title: 'Protein · Before bed' },
    { value: 'veggies', title: 'Veggies' },
    { value: 'carbs_recommended', title: 'Carbs · Recommended' },
    { value: 'carbs_sparingly', title: 'Carbs · Use sparingly' },
    { value: 'fat_recommended', title: 'Fat · Recommended' },
    { value: 'fat_sparingly', title: 'Fat · Use sparingly' },
] as const;

export type PantryCollection = (typeof COLLECTIONS)[number]['value'];
export type PantrySource = (typeof SOURCES)[number]['value'];
export type PantryGroup = (typeof GROUPS)[number]['value'];

/** FOOD-009. */
export const UNITS: PantryUnit[] = ['g', 'oz'];

export function isGroup(value: unknown): value is PantryGroup {
    return GROUPS.some((group) => group.value === value);
}
