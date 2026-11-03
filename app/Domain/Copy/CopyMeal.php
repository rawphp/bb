<?php

namespace App\Domain\Copy;

use App\Domain\MealFit\MealFood;
use App\Domain\Model\MealTarget;

/** A meal as the copy rules see it: a source to copy from or a destination to copy into (MEAL-060). */
final readonly class CopyMeal
{
    /**
     * @param  ?string  $time  "HH:MM"; null when the meal has none
     * @param  list<MealFood>  $entries  its planned foods and recipe servings in their order, each id distinct within the meal
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $time,
        public MealTarget $target,
        public bool $isCheckedIn,
        public array $entries,
    ) {}
}
