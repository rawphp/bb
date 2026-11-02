<?php

namespace App\Capabilities\Reads\Pantry;

use App\Actions\Foods\SearchFoods;
use App\Capabilities\Nutrition\Data\FoodSearchInput;
use App\Domain\Foods\FoodSearchFilters;
use App\Domain\Foods\FoodSearchSource;
use App\Domain\MealFit\CuratedGroup;
use App\Domain\MealFit\Food as MealFitFood;
use App\Domain\MealFit\MealFood;
use App\Domain\MealFit\Weighing;
use App\Domain\Model\DietFilter;
use App\Domain\Pantry\PantryFood;
use App\Domain\Pantry\PantryRecipe;
use App\Domain\Pantry\PantrySavedMeal;
use App\Domain\Pantry\PantryTab;
use App\Domain\Recipe\RecipeMacros;
use App\Models\Food;
use App\Models\FoodFavorite;
use App\Models\FoodRecent;
use App\Models\PlannedFood;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\SavedMeal;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Reads what one food_search shows ({@see PantrySnapshot}). A tab reads its own list (rp-clone's food list endpoints),
 * a search runs {@see SearchFoods} under the user's diet filters, and the Recipes and Saved Meals filters read those.
 */
final class PantryReader
{
    /** rp-clone's Recents list: the last 100 foods the user used. */
    public const RECENTS = 100;

    public function __construct(private readonly SearchFoods $search) {}

    public function read(User $user, FoodSearchInput $input): PantrySnapshot
    {
        $diets = self::diets($user);
        $query = trim($input->query);
        $mealFoods = $input->meal_id === null ? [] : self::mealFoods($user, $input->meal_id);

        if ($input->collection === 'recipes') {
            $recipes = self::recipes($user, $diets, $query);
            $hidden = $recipes === [] && $diets !== [] && $query === ''
                && Recipe::query()->where(fn (Builder $q) => $q->where('user_id', $user->id)->orWhereNull('user_id'))->exists();

            return new PantrySnapshot(diets: $diets, mealFoods: $mealFoods, recipes: $recipes, recipesHidden: $hidden);
        }
        if ($input->collection === 'saved_meals') {
            return new PantrySnapshot(diets: $diets, mealFoods: $mealFoods, savedMeals: self::savedMeals($user, $query));
        }

        $suggestion = null;
        if ($query === '') {
            $found = self::tab($user, PantryTab::from($input->tab));
        } else {
            $filters = new FoodSearchFilters(
                source: FoodSearchSource::tryFrom((string) $input->source),
                group: CuratedGroup::tryFrom((string) $input->group),
                diets: $diets,
            );
            $found = ($this->search)($user, $query, $filters);
            $suggestion = $this->search->didYouMean($user, $query, $found, $filters);
        }

        $ids = $found->modelKeys();
        $favoriteIds = self::idsIn(FoodFavorite::query(), $user, $ids);
        $ownIds = $query === '' ? [] : array_values(array_unique([
            ...$favoriteIds,
            ...self::idsIn(FoodRecent::query(), $user, $ids),
            ...$found->where('user_id', $user->id)->modelKeys(),
        ]));

        return new PantrySnapshot(
            diets: $diets,
            foods: $found->map(self::pantryFood(...))->values()->all(),
            ownIds: $ownIds,
            favoriteIds: $favoriteIds,
            mealFoods: $mealFoods,
            suggestion: $suggestion,
        );
    }

    /**
     * FOOD-020: a tab's foods. Curated is every shared curated food by name (a few hundred rows); Recents the user's
     * last 100, newest first; Favorites and Custom by name. A recent or favourite never reveals another user's custom food.
     *
     * @return Collection<int, Food>
     */
    private static function tab(User $user, PantryTab $tab): Collection
    {
        return match ($tab) {
            PantryTab::Curated => Food::query()->whereNull('user_id')->where('curated', true)->orderBy('name')->orderBy('id')->get(),
            PantryTab::Recents => new Collection(FoodRecent::query()
                ->where('user_id', $user->id)
                ->whereHas('food', fn (Builder $food) => $food->visibleTo($user))
                ->with('food')
                ->orderByDesc('used_at')->orderByDesc('id')
                ->limit(self::RECENTS)
                ->get()->pluck('food')->all()),
            PantryTab::Favorites => Food::query()->visibleTo($user)
                ->whereIn('id', FoodFavorite::query()->where('user_id', $user->id)->select('food_id'))
                ->orderBy('name')->orderBy('id')->get(),
            PantryTab::Custom => Food::query()->where('user_id', $user->id)->orderBy('name')->orderBy('id')->get(),
        };
    }

    /**
     * The user's rows of a per-food table among the given food ids.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $rows
     * @param  list<string>  $foodIds
     * @return list<string>
     */
    private static function idsIn(Builder $rows, User $user, array $foodIds): array
    {
        if ($foodIds === []) {
            return [];
        }

        return $rows->where('user_id', $user->id)->whereIn('food_id', $foodIds)->pluck('food_id')->map(fn ($id) => (string) $id)->all();
    }

    /** @return list<DietFilter> FOOD-027: the profile's filters; a value this version does not know is ignored. */
    private static function diets(User $user): array
    {
        $values = $user->profile?->getAttribute('diet_filters');

        return array_values(array_filter(array_map(
            fn (mixed $value) => is_string($value) ? DietFilter::tryFrom($value) : null,
            is_array($values) ? $values : [],
        )));
    }

    /**
     * FOOD-028: the foods already in the meal, in the meal's order, each once.
     *
     * @return array<string, string> food id => name
     */
    private static function mealFoods(User $user, string $mealId): array
    {
        $foods = [];
        foreach (PlannedFood::query()->where('user_id', $user->id)->where('meal_id', $mealId)->with('food')->orderBy('position')->get() as $planned) {
            $foods[(string) $planned->food_id] ??= (string) $planned->food?->name;
        }

        return $foods;
    }

    /**
     * RCP-020, FOOD-027: the user's own recipes by name, then the library's, that pass every diet filter on and hold
     * the search text in their name.
     *
     * @param  list<DietFilter>  $diets
     * @return list<PantryRecipe>
     */
    private static function recipes(User $user, array $diets, string $query): array
    {
        $load = fn (?User $owner) => self::named(Recipe::query()->ownedBy($owner), $query)
            ->filtered(null, array_column($diets, 'value'))
            ->with('ingredients.food')->orderBy('name')->orderBy('id')->get();

        return $load($user)->concat($load(null))->map(fn (Recipe $recipe) => new PantryRecipe(
            id: (string) $recipe->id,
            name: $recipe->name,
            mealType: $recipe->meal_type,
            perServing: RecipeMacros::perServing(
                $recipe->ingredients->map(fn (RecipeIngredient $ingredient) => new MealFood(
                    id: (string) $ingredient->id,
                    food: self::mealFitFood($ingredient->food),
                    grams: $ingredient->grams,
                    weighing: $ingredient->is_cooked ? Weighing::Cooked : Weighing::Raw,
                ))->values()->all(),
                $recipe->servings,
            ),
            isLibrary: $recipe->user_id === null,
        ))->values()->all();
    }

    /** @return list<PantrySavedMeal> MEAL-071: the user's saved meals by name that hold the search text in their name. */
    private static function savedMeals(User $user, string $query): array
    {
        return self::named(SavedMeal::query()->where('user_id', $user->id), $query)
            ->withCount('foods')->orderBy('name')->orderBy('id')->get()
            ->map(fn (SavedMeal $meal) => new PantrySavedMeal((string) $meal->id, $meal->name, (int) $meal->getAttribute('foods_count')))
            ->values()->all();
    }

    /**
     * Rows whose name holds the search text, in any case (the column's collation ignores case).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $rows
     * @return Builder<TModel>
     */
    private static function named(Builder $rows, string $query): Builder
    {
        return $query === '' ? $rows : $rows->where('name', 'like', '%'.addcslashes($query, '\\%_').'%');
    }

    private static function pantryFood(Food $food): PantryFood
    {
        return new PantryFood(
            food: self::mealFitFood($food),
            brand: $food->brand,
            isCurated: (bool) $food->curated,
            diets: self::dietsOf($food),
        );
    }

    private static function mealFitFood(Food $food): MealFitFood
    {
        return new MealFitFood(
            id: (string) $food->id,
            name: $food->name,
            kcal: $food->kcal,
            proteinG: $food->protein,
            fatG: $food->fat,
            carbsG: $food->carbs,
            cookedRatio: $food->cooked_ratio,
            curatedGroup: CuratedGroup::tryFrom((string) $food->curated_group),
        );
    }

    /** @return list<DietFilter> the diet flags a food has set */
    private static function dietsOf(Food $row): array
    {
        return array_values(array_filter(DietFilter::cases(), fn (DietFilter $filter) => (bool) $row->getAttribute($filter->value)));
    }
}
