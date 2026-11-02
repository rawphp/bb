<?php

namespace App\Capabilities\Workout\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Skip the whole session (WO-050): marks it skipped and applies SCH-053. The
 * view asks for confirmation first; it cannot be undone.
 */
final class WorkoutSkipInput extends CapabilityData
{
    public function __construct(
        public readonly string $workout_id,
    ) {}

    public static function rules(): array
    {
        return ['workout_id' => ['ulid']];
    }
}
