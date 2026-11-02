<?php

namespace App\Capabilities\Workout\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Delete a set (WO-020). Dropping a removed set from later weeks belongs to
 * finishing the workout (PROG-019, workout_finish), not to this write.
 */
final class WorkoutSetDeleteInput extends CapabilityData
{
    public function __construct(
        public readonly string $set_id,
    ) {}

    public static function rules(): array
    {
        return ['set_id' => ['ulid']];
    }
}
