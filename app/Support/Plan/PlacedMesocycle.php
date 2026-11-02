<?php

namespace App\Support\Plan;

use App\Domain\Plan\PlannedMesocycle;
use App\Models\Mesocycle;

/** A saved mesocycle placed on the plan-week grid (PLAN-002, SCH-060). MESO-043: one ended early has no deload. */
final readonly class PlacedMesocycle
{
    public function __construct(
        public Mesocycle $mesocycle,
        public PlannedMesocycle $placed,
        public bool $isEnded,
    ) {}
}
