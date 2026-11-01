<?php

namespace App\Domain\MealFit;

use App\Domain\Model\MealTarget;

/** A fitted meal: its foods in their original order, the totals, the badge and what is short. */
final readonly class MealFitResult
{
    /**
     * @param  list<MealFood>  $foods
     * @param  list<Shortfall>  $shortfalls  In protein, fat, carbs order.
     */
    public function __construct(
        public array $foods,
        public MealTarget $totals,
        public bool $targetsMet,
        public array $shortfalls,
    ) {}
}
