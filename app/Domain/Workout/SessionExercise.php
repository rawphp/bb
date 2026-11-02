<?php

namespace App\Domain\Workout;

use App\Domain\Model\MuscleGroup;

/** One library exercise of a session, in the session's order, as the Feedback sheets read it (FB-001, FB-002). */
final readonly class SessionExercise
{
    /**
     * @param  int  $sets  how many sets of it the session holds
     * @param  bool  $isDone  every one of those sets is logged or skipped
     */
    public function __construct(
        public int $exerciseId,
        public string $name,
        public MuscleGroup $muscle,
        public int $sets,
        public bool $isDone,
    ) {}
}
