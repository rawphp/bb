<?php

namespace App\Actions\SavedMeals;

use App\Domain\MealFit\Food as MealFitFood;
use App\Domain\MealFit\MealFood;
use App\Domain\MealFit\Weighing;
use App\Domain\Model\MealTarget;
use App\Domain\Recipe\RecipeMacros;
use App\Domain\Recipe\RecipeServing;
use App\Domain\SavedMeals\SavedMealLoad;
use App\Models\DayTargetMeal;
use App\Models\Food;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\RecipeIngredient;
use App\Models\SavedMeal;
use App\Models\SavedMealFood;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * MEAL-070: load the user's saved meal into one of their meals (SavedMealLoad). The joined foods are planned after
 * the meal's foods and recipe servings, with their saved cooked state and unit, and every amount the fit moved is saved.
 */
final class LoadSavedMeal
{
    public function __construct(private readonly SavedMealLoad $load) {}

    /** @return array{date: string, name: string, added: int} the meal's day, the saved meal's name and how many foods joined */
    public function __invoke(User $user, string $mealId, string $savedMealId): array
    {
        $meal = DayTargetMeal::query()->liveOf($user)
            ->with(['dayTarget', 'plannedFoods.food', 'plannedRecipes.sourceRecipe.ingredients.food'])
            ->findOrFail($mealId);
        $saved = SavedMeal::query()->where('user_id', $user->id)->with('foods.food')->findOrFail($savedMealId);
        $day = $meal->dayTarget;

        $foods = $meal->plannedFoods->keyBy(fn (PlannedFood $f) => "food:{$f->id}");
        $recipes = $meal->plannedRecipes->keyBy(fn (PlannedRecipe $r) => "recipe:{$r->id}");
        $joining = $saved->foods->keyBy(fn (SavedMealFood $f) => "saved:{$f->id}");

        // The meal's foods and recipe servings in their planned order, as the meal screen shows them.
        $entries = [
            ...$foods->map(fn (PlannedFood $f, string $id) => [$f->position, self::food($id, $f->food, $f->grams, $f->is_cooked, $f->is_locked)])->values(),
            ...$recipes->map(fn (PlannedRecipe $r, string $id) => [$r->position, self::recipe($id, $r)])->values(),
        ];
        usort($entries, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $loaded = $this->load->load(
            array_column($entries, 1),
            $joining->map(fn (SavedMealFood $f, string $id) => self::food($id, $f->food, $f->grams, $f->is_cooked, $f->is_locked))->values()->all(),
            self::target($meal, $day->meals),
        );

        $added = DB::transaction(function () use ($user, $meal, $foods, $recipes, $joining, $loaded): int {
            $position = max([-1, ...$foods->pluck('position')->all(), ...$recipes->pluck('position')->all()]) + 1;
            $added = 0;
            foreach ($loaded as $entry) {
                if ($foods->has($entry->id)) {
                    $foods[$entry->id]->update(['grams' => $entry->grams]);
                } elseif ($recipes->has($entry->id)) {
                    $recipes[$entry->id]->update(['servings' => RecipeServing::servings($entry)]);
                } else {
                    $food = $joining[$entry->id];
                    PlannedFood::query()->create([
                        'user_id' => $user->id,
                        'meal_id' => $meal->id,
                        'food_id' => $food->food_id,
                        'position' => $position++,
                        'grams' => $entry->grams,
                        'is_cooked' => $food->is_cooked,
                        'is_locked' => $food->is_locked,
                        'unit' => $food->unit,
                    ]);
                    $added++;
                }
            }

            return $added;
        });

        return ['date' => TrainSnapshot::day($day->date)->format('Y-m-d'), 'name' => $saved->name, 'added' => $added];
    }

    /**
     * The meal's targets, kept in its day's meals (NUT-030).
     *
     * @param  list<array<string, mixed>>  $meals
     */
    private static function target(DayTargetMeal $meal, array $meals): MealTarget
    {
        foreach ($meals as $target) {
            if (strcasecmp((string) ($target['id'] ?? ''), (string) $meal->id) === 0) {
                return new MealTarget(
                    kcal: (float) ($target['kcal'] ?? 0),
                    proteinG: (float) ($target['protein_g'] ?? 0),
                    fatG: (float) ($target['fat_g'] ?? 0),
                    carbsG: (float) ($target['carbs_g'] ?? 0),
                );
            }
        }

        throw (new ModelNotFoundException)->setModel(DayTargetMeal::class, [$meal->id]);
    }

    private static function food(string $id, Food $food, float $grams, bool $isCooked, bool $isLocked): MealFood
    {
        return new MealFood(
            id: $id,
            food: new MealFitFood(
                id: (string) $food->id,
                name: $food->name,
                kcal: $food->kcal,
                proteinG: $food->protein,
                fatG: $food->fat,
                carbsG: $food->carbs,
                cookedRatio: $food->cooked_ratio,
            ),
            grams: $grams,
            weighing: $isCooked ? Weighing::Cooked : Weighing::Raw,
            isLocked: $isLocked,
        );
    }

    /**
     * RCP-002: servings of a recipe at its ingredients' macros per serving. The recipe is always there: a planned
     * recipe goes with it on delete (cascade), and a soft-deleted one still loads (withTrashed).
     */
    private static function recipe(string $id, PlannedRecipe $planned): MealFood
    {
        $recipe = $planned->sourceRecipe;
        $perServing = RecipeMacros::perServing(
            $recipe->ingredients->map(fn (RecipeIngredient $i) => self::food("ingredient:{$i->id}", $i->food, (float) $i->grams, (bool) $i->is_cooked, false))->values()->all(),
            (float) $recipe->servings,
        );

        return RecipeServing::food($id, $recipe->name, $perServing, $planned->servings, $planned->is_locked);
    }
}
