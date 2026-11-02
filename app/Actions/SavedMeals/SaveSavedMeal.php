<?php

namespace App\Actions\SavedMeals;

use App\Domain\SavedMeals\SavedMeals;
use App\Models\DayTargetMeal;
use App\Models\Food;
use App\Models\PlannedFood;
use App\Models\SavedMeal;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * MEAL-070: save a meal's foods under a name, with their exact amounts, cooked state, locks and unit (MEAL-005).
 * Recipe servings are not saved: a saved meal holds foods. FOOD-046: or save the foods given, such as a scanned
 * meal's, locked, raw and in grams. A save under the id of a saved meal the user already has returns it unchanged,
 * so a retry never makes a second one and never replaces its foods (CAP-019).
 */
final class SaveSavedMeal
{
    /**
     * @param  SavedMeal|null  $existing  the user's saved meal with `$id`, returned as it is; null creates one under it
     * @param  list<array{food_id: string, grams: float|int}>  $foods
     */
    public function __invoke(User $user, ?SavedMeal $existing, string $id, string $name, ?string $mealId, array $foods): SavedMeal
    {
        if ($existing !== null) {
            return $existing;
        }

        $name = SavedMeals::name($name) ?? throw new DomainException('Give the saved meal a name.');
        $rows = $mealId === null ? $this->givenFoods($user, $foods) : $this->mealFoods($user, $mealId);

        return DB::transaction(function () use ($user, $id, $name, $rows): SavedMeal {
            $meal = (new SavedMeal)->forceFill(['id' => $id, 'user_id' => $user->id, 'name' => $name]);
            $meal->save();

            foreach ($rows as $position => $row) {
                $meal->foods()->create($row + ['position' => $position]);
            }

            return $meal;
        });
    }

    /** @return list<array{food_id: string, grams: float, is_cooked: bool, is_locked: bool, unit: string}> */
    private function mealFoods(User $user, string $mealId): array
    {
        $meal = DayTargetMeal::query()->liveOf($user)->findOrFail($mealId);
        $planned = $meal->plannedFoods()->limit(SavedMeals::MAX_FOODS)->get();
        if ($planned->isEmpty()) {
            throw new DomainException('That meal has no foods to save.');
        }

        return $planned->map(fn (PlannedFood $f) => [
            'food_id' => (string) $f->food_id,
            'grams' => $f->grams,
            'is_cooked' => $f->is_cooked,
            'is_locked' => $f->is_locked,
            'unit' => $f->unit,
        ])->values()->all();
    }

    /**
     * @param  list<array{food_id: string, grams: float|int}>  $foods
     * @return list<array{food_id: string, grams: float, is_cooked: bool, is_locked: bool, unit: string}>
     */
    private function givenFoods(User $user, array $foods): array
    {
        if ($foods === []) {
            throw new DomainException('Add a food to save.');
        }
        if (count($foods) > SavedMeals::MAX_FOODS) {
            throw new DomainException('A saved meal holds at most '.SavedMeals::MAX_FOODS.' foods.');
        }

        $ids = array_values(array_unique(array_column($foods, 'food_id')));
        $visible = Food::query()->visibleTo($user)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (string) $id)->all();
        $missing = array_values(array_diff($ids, $visible));
        if ($missing !== []) {
            throw (new ModelNotFoundException)->setModel(Food::class, $missing);
        }

        return array_map(fn (array $food) => [
            'food_id' => $food['food_id'],
            'grams' => (float) $food['grams'],
            'is_cooked' => false,
            'is_locked' => true,
            'unit' => 'g',
        ], $foods);
    }
}
