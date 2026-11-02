<?php

namespace App\Domain\Retarget;

use App\Domain\MealSplit\CarbPlacement;
use App\Domain\Placement\ClockRange;

/** The schedule settings a re-target places and splits meals with. */
final readonly class RetargetContext
{
    /**
     * @param  float  $weightKg  NUT-003: the trend weight (WT-011).
     * @param  list<ClockRange>  $busy
     * @param  bool  $isDeloadWeek  TBL-031: a deload week's sessions are Light whatever their length.
     * @param  bool  $countsWorkoutEnergy  SCH-060: false in an away week on rest-day calories, whose targets leave workout energy out.
     */
    public function __construct(
        public float $weightKg,
        public ClockRange $eatingWindow,
        public array $busy = [],
        public CarbPlacement $carbPlacement = CarbPlacement::TrainingCentred,
        public bool $isDeloadWeek = false,
        public bool $countsWorkoutEnergy = true,
    ) {}
}
