<?php

namespace App\Domain\Progression;

use App\Domain\Model\JointPain;
use App\Domain\Model\MuscleGroup;

/** What RecoveryLoad needs about one exercise. Weights are in the user's unit. */
final readonly class RecoveryLoadInputs
{
    /**
     * @param  list<PlannedLoad>  $planned  the PROG-020 targets of the exercise
     * @param  list<SetLoad>  $lastWeek  last week's logged sets of this exercise on this day
     * @param  ?int  $rir  null in a deload week
     * @param  ?MuscleGroup  $flaggedMuscle  the muscle, when Underperformance flags it over the sessions before this one
     * @param  list<LoggedExercise>  $history  this exercise as logged in the sessions before this one, oldest first
     * @param  ?JointPain  $lastWeekJointPain  the exercise's FB-002 answer in last week's same-day session
     */
    public function __construct(
        public array $planned,
        public array $lastWeek,
        public ?int $rir,
        public bool $isDeload,
        public bool $isFatLoss,
        public LoadRules $rules,
        public MuscleGroup $muscle,
        public ?MuscleGroup $flaggedMuscle,
        public array $history,
        public ?JointPain $lastWeekJointPain,
    ) {}
}
