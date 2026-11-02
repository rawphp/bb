<?php

namespace App\Domain\Plan;

use App\Domain\Model\Phase;

/** A prebuilt plan laid on the week grid (PLAN-030). */
final readonly class PlanLayout
{
    /**
     * @param  list<Phase>  $phases
     * @param  list<PlannedMesocycle>  $mesocycles
     * @param  list<int>  $activeRestWeeks  MESO-064: the deload of the mesocycle before it, plus one week off
     */
    public function __construct(
        public array $phases,
        public array $mesocycles,
        public array $activeRestWeeks,
    ) {}
}
