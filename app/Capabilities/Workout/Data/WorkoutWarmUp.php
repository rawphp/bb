<?php

namespace App\Capabilities\Workout\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * WO-024: the warm-up card on the session's first exercise. "Got it" hides it
 * on the device; it is not a write.
 */
final class WorkoutWarmUp extends CapabilityData
{
    /**
     * @param  list<WorkoutWarmUpStep>  $steps
     */
    public function __construct(
        public readonly string $workout_exercise_id,
        public readonly string $title,
        #[Field(items: WorkoutWarmUpStep::class)]
        public readonly array $steps,
        public readonly string $note,
    ) {}
}
