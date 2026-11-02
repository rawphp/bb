<?php

namespace App\Domain\DayBalance;

use App\Domain\Model\MealTarget;

/** A meal and the macros it is to hold. */
final readonly class Slot
{
    public function __construct(
        public string $id,
        public MealTarget $target,
    ) {}
}
