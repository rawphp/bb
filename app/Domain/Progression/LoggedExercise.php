<?php

namespace App\Domain\Progression;

use App\Domain\Model\ClosedRange;
use App\Domain\Model\MuscleGroup;

/** One exercise as logged in one session, the input to the recovery flags. */
final readonly class LoggedExercise
{
    /**
     * @param  list<LoggedSet>  $sets
     */
    public function __construct(
        public string $exerciseName,
        public MuscleGroup $muscle,
        public ClosedRange $repRange,
        public array $sets,
    ) {}
}
