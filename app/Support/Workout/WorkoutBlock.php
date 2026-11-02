<?php

namespace App\Support\Workout;

use App\Domain\Progression\FeedbackBlock;
use App\Models\Exercise;

/** One exercise of a workout with its sets (WO-002), planned from a slot or stored as a workout_exercises row. */
final readonly class WorkoutBlock
{
    /**
     * @param  list<WorkoutSetRow>  $sets
     */
    public function __construct(
        public string $id,
        public Exercise $exercise,
        public ?string $note,
        public array $sets,
    ) {}

    /** rp-clone isDone: every set is logged or skipped; an exercise with no sets has nothing left to do. */
    public function isDone(): bool
    {
        foreach ($this->sets as $set) {
            if (! $set->isDone()) {
                return false;
            }
        }

        return true;
    }

    public function feedback(): FeedbackBlock
    {
        return new FeedbackBlock($this->id, (int) $this->exercise->id, $this->exercise->name, $this->exercise->muscle_group, count($this->sets), $this->isDone());
    }
}
