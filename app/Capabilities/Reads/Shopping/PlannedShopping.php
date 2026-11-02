<?php

namespace App\Capabilities\Reads\Shopping;

use App\Domain\MealFit\CuratedGroup;
use App\Domain\MealFit\Weighing;
use App\Domain\Shopping\DateRange;
use App\Domain\Shopping\FoodAmount;
use App\Domain\Shopping\PlannedFoodDay;
use App\Domain\Shopping\PlannedItem;
use App\Domain\Shopping\Recipe;
use App\Domain\Shopping\ShoppingFood;
use App\Domain\Shopping\ShoppingGroup;
use App\Domain\Shopping\ShoppingList;
use App\Domain\Shopping\StoreCategory;
use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use App\Models\Food;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\RecipeIngredient;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Today\TodaySnapshot;
use DateTimeZone;

/**
 * SHOP-001, SHOP-002: the foods planned in the user's meals over a range of days, as the shopping list
 * builds them (rp-clone ShoppingSource). A planned recipe breaks down into its ingredients, read as
 * Today reads them.
 */
final class PlannedShopping
{
    /** @return list<ShoppingGroup> */
    public static function groups(User $user, DateRange $range): array
    {
        $utc = new DateTimeZone('UTC');
        $days = DayTarget::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$range->lower->format('Y-m-d'), $range->upper->format('Y-m-d')])
            ->with(['mealIndex.plannedFoods.food', 'mealIndex.plannedRecipes.sourceRecipe.ingredients.food'])
            ->get()
            ->map(fn (DayTarget $day) => new PlannedFoodDay(
                TrainSnapshot::day($day->date),
                $day->mealIndex->flatMap(fn (DayTargetMeal $meal) => [
                    ...$meal->plannedFoods->map(fn (PlannedFood $planned) => PlannedItem::food(
                        self::amount($planned->food, $planned->grams, $planned->is_cooked),
                    )),
                    ...$meal->plannedRecipes->map(fn (PlannedRecipe $planned) => self::recipe($planned)),
                ])->filter()->values()->all(),
            ))
            ->values()
            ->all();

        return ShoppingList::build($days, $range, $utc);
    }

    /** A recipe with no ingredients to read adds nothing. */
    private static function recipe(PlannedRecipe $planned): ?PlannedItem
    {
        $recipe = $planned->sourceRecipe;
        if ($recipe === null) {
            return null;
        }

        return PlannedItem::recipe(new Recipe(
            (string) (TodaySnapshot::json($planned->recipe)['name'] ?? $recipe->name),
            $recipe->servings,
            $recipe->ingredients->map(fn (RecipeIngredient $i) => self::amount($i->food, $i->grams, $i->is_cooked))->values()->all(),
        ), $planned->servings);
    }

    private static function amount(Food $food, float $grams, bool $cooked): FoodAmount
    {
        return new FoodAmount(
            new ShoppingFood(
                id: (string) $food->id,
                name: $food->name,
                category: StoreCategory::of($food->name, CuratedGroup::tryFrom((string) $food->curated_group)),
                cookedRatio: $food->cooked_ratio,
            ),
            $grams,
            $cooked ? Weighing::Cooked : Weighing::Raw,
        );
    }
}
