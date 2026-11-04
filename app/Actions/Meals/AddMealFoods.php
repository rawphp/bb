<?php

namespace App\Actions\Meals;

use App\Capabilities\Nutrition\Data\MealFoodAddEntry;
use App\Capabilities\Support\FieldRefused;
use App\Domain\MealFit\MealEdit;
use App\Domain\MealFit\Weighing;
use App\Models\DayTargetMeal;
use App\Models\Food;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * MEAL-002, RCP-001, FOOD-045: foods and recipes join the user's meal, each as the row the client's ULID names (CAP-019),
 * and the whole meal is fitted to its targets (MealEdit). A food or recipe the meal already holds is not added twice.
 * A scanned food, given its grams, joins locked at them.
 */
final class AddMealFoods
{
    public const RECIPE_IN_SERVINGS = 'A recipe is added in servings, not grams.';

    public function __construct(private readonly MealEdit $edit) {}

    /** @param  list<MealFoodAddEntry>  $entries */
    public function __invoke(User $user, string $mealId, array $entries): DayTargetMeal
    {
        return DB::transaction(function () use ($user, $mealId, $entries): DayTargetMeal {
            $meal = MealEntries::load($user, $mealId);
            $before = $meal->entries();

            foreach ($entries as $index => $entry) {
                $this->join($user, $meal, $entry, $index);
            }

            $joined = array_slice($meal->entries(), count($before));
            if ($joined !== []) {
                $meal->save($this->edit->planned($this->edit->add($before, $joined, $meal->target())));
            }

            return $meal->meal;
        });
    }

    private function join(User $user, MealEntries $meal, MealFoodAddEntry $entry, int $index): void
    {
        if ($entry->recipe_id !== null && $entry->grams !== null) {
            throw new FieldRefused("entries.{$index}.grams", self::RECIPE_IN_SERVINGS);
        }

        $id = strtolower($entry->id);
        // An id this meal already holds is a retry of this entry. Any other row's id is spent.
        if ($meal->holds($id)) {
            return;
        }
        if (PlannedFood::query()->whereKey($id)->exists() || PlannedRecipe::query()->whereKey($id)->exists()) {
            throw (new ModelNotFoundException)->setModel(PlannedFood::class, [$id]);
        }

        if ($entry->recipe_id !== null) {
            if (! $meal->holdsRecipe($entry->recipe_id)) {
                $recipe = Recipe::query()->visibleTo($user)->with('ingredients.food')->findOrFail($entry->recipe_id);
                $meal->addRecipe($user, $id, $recipe, 0);
            }

            return;
        }

        if (! $meal->holdsFood((string) $entry->food_id)) {
            $food = Food::query()->visibleTo($user)->with('servings')->findOrFail($entry->food_id);
            $cooked = $entry->weighing === Weighing::Cooked->value && $food->cooked_ratio !== null && $food->cooked_ratio > 0;
            $meal->addFood($user, $id, $food, $entry->grams ?? 0.0, $cooked, $entry->grams !== null);
        }
    }
}
