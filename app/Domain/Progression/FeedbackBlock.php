<?php

namespace App\Domain\Progression;

use App\Domain\Model\MuscleGroup;

/** An exercise of the session as the Feedback rules see it: its muscle, how many sets it has and whether all are done. */
final readonly class FeedbackBlock
{
    public function __construct(
        public string $id,
        public int $exerciseId,
        public string $exerciseName,
        public MuscleGroup $muscle,
        public int $sets,
        public bool $isDone,
    ) {}
}
