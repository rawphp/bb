<?php

namespace App\Domain\Placement;

/** A meal to place. A meal with a fixed time keeps it: a hand-set time (SCH-044), or a checked-in meal when optimizing (SCH-036). */
final readonly class MealSlot
{
    public function __construct(
        public string $id,
        public ?ClockTime $fixedTime = null,
    ) {}
}
