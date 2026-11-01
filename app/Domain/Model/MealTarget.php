<?php

namespace App\Domain\Model;

/** Calories and macros for a meal or a day. */
final readonly class MealTarget
{
    public function __construct(
        public float $kcal,
        public float $proteinG,
        public float $fatG,
        public float $carbsG,
    ) {}
}
