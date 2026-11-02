<?php

namespace App\Domain\Plan;

/** The mesocycle covering a plan week: its index in the list asked about and its place on the grid (PLAN-002). */
final readonly class CoveringMesocycle
{
    public function __construct(
        public int $index,
        public PlannedMesocycle $placed,
    ) {}
}
