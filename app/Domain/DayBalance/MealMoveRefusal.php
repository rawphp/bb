<?php

namespace App\Domain\DayBalance;

use DomainException;

/** A calorie move between meals that the app will not make (DAY-020, SAFE-010). */
final class MealMoveRefusal extends DomainException
{
    private function __construct(
        public readonly MealMoveRefusalReason $reason,
        public readonly ?string $id = null,
    ) {
        parent::__construct(match ($reason) {
            MealMoveRefusalReason::Invalid => 'The move needs positive calories between two different meals.',
            MealMoveRefusalReason::NoMeals => 'There is no meal to carry the change in carbs.',
            MealMoveRefusalReason::BelowCarbMinimum => "Meal {$id} would keep less than 5 g of carbs.",
        });
    }

    public static function invalid(): self
    {
        return new self(MealMoveRefusalReason::Invalid);
    }

    public static function noMeals(): self
    {
        return new self(MealMoveRefusalReason::NoMeals);
    }

    /** SAFE-010. */
    public static function belowCarbMinimum(string $id): self
    {
        return new self(MealMoveRefusalReason::BelowCarbMinimum, $id);
    }
}
