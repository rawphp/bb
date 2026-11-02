<?php

namespace App\Domain\Mesocycle;

use App\Domain\Model\MuscleGroup;

/** A library exercise as the Meso Builder sees it: what it trains and how it ranks. */
final readonly class Exercise
{
    public function __construct(
        public string $name,
        public MuscleGroup $muscle,
        public StimulusToFatigue $stimulusToFatigue,
        public bool $isCompound,
    ) {}
}
