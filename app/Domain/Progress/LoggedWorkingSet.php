<?php

namespace App\Domain\Progress;

use App\Domain\Model\MuscleGroup;

/** A logged working set, reduced to what the history counts: where it was done and which muscle it trained. */
final readonly class LoggedWorkingSet
{
    public function __construct(
        public string $mesocycleId,
        /** The mesocycle week the set's session was in. */
        public int $week,
        public MuscleGroup $muscle,
    ) {}
}
