<?php

namespace App\Support\Today;

use App\Domain\Model\MealTarget;

/** A food or recipe servings planned in a meal, with the macros of its amount. */
final readonly class TodayPlannedEntry
{
    /**
     * @param  string  $amount  "80 g" or "1.5 servings"
     * @param  int  $position  Its place in the meal; foods and recipes share one order.
     */
    public function __construct(
        public string $name,
        public string $amount,
        public MealTarget $macros,
        public int $position = 0,
    ) {}
}
