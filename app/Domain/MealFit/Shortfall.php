<?php

namespace App\Domain\MealFit;

/** MEAL-007: a macro the chosen foods cannot reach, and the curated group to add from. */
final readonly class Shortfall
{
    public function __construct(
        public Macro $macro,
        public float $shortG,
        public CuratedGroup $group,
    ) {}
}
