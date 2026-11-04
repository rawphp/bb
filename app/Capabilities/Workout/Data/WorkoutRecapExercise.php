<?php

namespace App\Capabilities\Workout\Data;

use App\Capabilities\Support\Status;
use Rawphp\Capabilities\Support\CapabilityData;

/** WO-011: one exercise's best set in a finished workout, against its best in the same session last week. */
final class WorkoutRecapExercise extends CapabilityData
{
    public function __construct(
        public readonly string $workout_exercise_id,
        public readonly string $name,
        /** "82.5 kg × 10", "12 reps", or "No sets logged". */
        public readonly string $best_text,
        /** "+2.5 kg on last week", good when it went up; null with nothing logged now or last week. */
        public readonly ?Status $change,
    ) {}
}
