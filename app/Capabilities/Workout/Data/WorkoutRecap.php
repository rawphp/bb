<?php

namespace App\Capabilities\Workout\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** WO-011: the recap a finished workout opens on, worded by the server. */
final class WorkoutRecap extends CapabilityData
{
    /**
     * @param  list<WorkoutRecapStat>  $stats
     * @param  list<WorkoutRecapExercise>  $exercises
     */
    public function __construct(
        /** "Workout done". */
        public readonly string $title,
        /** Time, Sets and Lifted, in that order; Time only when the start and end are known. */
        #[Field(items: WorkoutRecapStat::class)]
        public readonly array $stats,
        /** Each exercise in the session's order. */
        #[Field(items: WorkoutRecapExercise::class)]
        public readonly array $exercises,
    ) {}
}
