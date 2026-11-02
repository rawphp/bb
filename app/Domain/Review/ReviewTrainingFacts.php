<?php

namespace App\Domain\Review;

/** The week's sessions and sets, counted from the plan and the training log. */
final readonly class ReviewTrainingFacts
{
    /**
     * @param  array<string, int>  $plannedSetsByMuscle  keyed by MuscleGroup value
     * @param  int  $loggedSets  working sets logged in the week
     */
    public function __construct(
        public int $plannedSessions,
        public int $plannedSets,
        public array $plannedSetsByMuscle,
        public int $doneSessions,
        public int $skippedSessions,
        public int $loggedSets,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0, [], 0, 0, 0);
    }
}
