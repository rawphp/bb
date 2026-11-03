import type { RecipeGenerateInput } from '@/contracts/recipe_generate';
import type { RecipeSaveInput } from '@/contracts/recipe_save';
import type { RecipesShowInput } from '@/contracts/recipes_show';

// The recipe screens' fixed choices and their words, as rp-clone names them. The values
// are the capabilities' input; the server applies them and sends the results.

export type RecipeSegment = NonNullable<RecipesShowInput['segment']>;
export type RecipeMealType = RecipeSaveInput['meal_type'];
export type RecipeComplexity = RecipeGenerateInput['complexity'];

/** RCP-020: My recipes and the Library. */
export const SEGMENTS: { value: RecipeSegment; title: string }[] = [
    { value: 'mine', title: 'My recipes' },
    { value: 'library', title: 'Library' },
];

export const MEAL_TYPES: { value: RecipeMealType; title: string }[] = [
    { value: 'breakfast', title: 'Breakfast' },
    { value: 'lunch', title: 'Lunch' },
    { value: 'dinner', title: 'Dinner' },
    { value: 'snack', title: 'Snack' },
];

/** RCP-010. */
export const COMPLEXITIES: { value: RecipeComplexity; title: string }[] = [
    { value: 'simple', title: 'Simple' },
    { value: 'moderate', title: 'Moderate' },
    { value: 'elaborate', title: 'Elaborate' },
];

/** RCP-020: the diet filters, App\Domain\Model\DietFilter's values and title() words, in its order. */
export const DIETS = [
    { value: 'dairy_free', title: 'Dairy free' },
    { value: 'gluten_free', title: 'Gluten free' },
    { value: 'grain_free', title: 'Grain free' },
    { value: 'low_fodmap', title: 'Low FODMAP' },
    { value: 'vegetarian', title: 'Ovo-lacto vegetarian' },
    { value: 'paleo', title: 'Paleo' },
    { value: 'vegan', title: 'Vegan' },
] as const;

export type RecipeDiet = (typeof DIETS)[number]['value'];

export function isMealType(value: unknown): value is RecipeMealType {
    return MEAL_TYPES.some((type) => type.value === value);
}

/** What was typed as a number, or null when it is blank or not one. A comma counts as a decimal point. */
export function typedNumber(text: string): number | null {
    const cleaned = text.trim().replace(',', '.');
    const value = cleaned === '' ? NaN : Number(cleaned);

    return Number.isFinite(value) ? value : null;
}

/** "1 serving", "4 servings", "1.5 servings". */
export function servingsText(servings: number): string {
    return `${servings} ${servings === 1 ? 'serving' : 'servings'}`;
}
