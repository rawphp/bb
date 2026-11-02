<?php

namespace App\Domain\MealSplit;

use App\Domain\Model\MealTarget;

/** One meal's or shake's share of the day. */
final readonly class MealSplitItem
{
    public function __construct(
        public string $id,
        public MealTarget $target,
    ) {}
}
