<?php

namespace App\Domain\Progress;

use App\Domain\Model\MuscleGroup;

final readonly class MuscleWeeklySets
{
    public function __construct(
        public MuscleGroup $muscle,
        /** Average working sets in a week, to one decimal. */
        public float $averageSets,
    ) {}
}
