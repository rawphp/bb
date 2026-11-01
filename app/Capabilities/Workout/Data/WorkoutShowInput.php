<?php

namespace App\Capabilities\Workout\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One session of a mesocycle by its week and day (WO-001). The same read
 * opens an earlier session for look back (WO-008), read only.
 */
final class WorkoutShowInput extends CapabilityData
{
    public function __construct(
        public readonly string $mesocycle_id,
        public readonly int $week,
        public readonly int $day,
    ) {}

    public static function rules(): array
    {
        return ['mesocycle_id' => ['required', 'ulid'], 'week' => ['required', 'integer', 'min:1'], 'day' => ['required', 'integer', 'min:1']];
    }
}
