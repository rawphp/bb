<?php

namespace App\Domain\Model;

/** One exercise in a session, with its planned working sets. */
final readonly class ExerciseSlot
{
    public function __construct(
        public string $id,
        public string $exerciseName,
        public MuscleGroup $muscle,
        public int $sets,
    ) {}
}
