<?php

namespace App\Capabilities\Workout\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Rename the session's day for this mesocycle (WO-031 Relabel day).
 */
final class WorkoutDayRelabelInput extends CapabilityData
{
    public function __construct(
        public readonly string $workout_id,
        #[Field(minLength: 1, maxLength: 60)]
        public readonly string $label,
    ) {}

    public static function rules(): array
    {
        return ['workout_id' => ['ulid']];
    }
}
