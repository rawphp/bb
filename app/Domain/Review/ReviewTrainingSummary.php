<?php

namespace App\Domain\Review;

use App\Domain\Model\MuscleGroup;

final readonly class ReviewTrainingSummary
{
    /**
     * @param  list<MuscleGroup>  $underperformingMuscles  PROG-050, by name
     * @param  list<MuscleGroup>  $musclesAtCeiling
     * @param  list<JointPainFlag>  $jointPain
     */
    public function __construct(
        public int $sessionsDone,
        public int $sessionsSkipped,
        public int $sessionsMissed,
        public int $setsDone,
        public int $setsPlanned,
        public array $underperformingMuscles,
        public array $musclesAtCeiling,
        public array $jointPain,
        public NextWeekKind $nextWeek,
    ) {}
}
