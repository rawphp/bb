<?php

namespace App\Actions\Meals;

use App\Capabilities\Support\FieldRefused;
use App\Domain\MealFit\MealEdit;
use App\Models\DayTargetMeal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * MEAL-004, RCP-002: a typed amount for one food or recipe serving. The entry takes it and stays put while the other
 * unlocked entries rebalance. The unit it is shown in does not change.
 */
final class SetMealFoodAmount
{
    public function __construct(private readonly MealEdit $edit) {}

    /** @throws FieldRefused when the entry has no such unit */
    public function __invoke(User $user, string $mealId, string $entryId, float $value, string $unit): DayTargetMeal
    {
        return DB::transaction(function () use ($user, $mealId, $entryId, $value, $unit): DayTargetMeal {
            $meal = MealEntries::load($user, $mealId);
            $entry = $meal->entry($entryId);
            $isServing = $meal->isServing($entryId);

            $grams = $this->edit->gramsFor($entry, $isServing, $value, $unit);
            if ($grams === null) {
                throw new FieldRefused('unit', match (true) {
                    $isServing => 'A recipe is set in servings.',
                    $unit === 'household' => 'This food has no household unit.',
                    default => 'Servings are for a recipe; set a food in g, oz or a household unit.',
                });
            }

            $meal->save($this->edit->planned($this->edit->setAmount($meal->entries(), $entry->id, $grams, $meal->target())));

            return $meal->meal;
        });
    }
}
