<?php

namespace App\Support\Today;

use App\Domain\Model\MealTarget;
use App\Domain\Placement\ClockTime;

/** A meal of a day as the Today rules read it: its target, and what was eaten when it is checked in. */
final readonly class DayMeal
{
    /**
     * @param  ?MealTarget  $eaten  the check-in; null while the meal is not checked in
     * @param  bool  $hasFoods  it has planned foods or recipes
     * @param  bool  $onlyLocked  it has planned foods or recipes and every one is locked (DAY-003)
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?ClockTime $time,
        public MealTarget $target,
        public ?MealTarget $eaten = null,
        public bool $hasFoods = false,
        public bool $onlyLocked = false,
    ) {}

    public function isChecked(): bool
    {
        return $this->eaten !== null;
    }
}
