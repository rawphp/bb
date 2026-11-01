<?php

namespace App\Domain\Model;

/** The MealFit rules Model needs. D_MEALFIT implements this in app/Domain/MealFit. */
interface MealFitRules
{
    /** MEAL-003: protein, fat and carbs are each within tolerance of the target. */
    public function targetsMet(MealTarget $total, MealTarget $target): bool;
}
