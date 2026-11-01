<?php

namespace App\Capabilities\Workout\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Start the session (WO-010, OFF-021): records when it began. Sent before the
 * first change to a not-started session; the start time is the outbox
 * `occurred_at` (OFF-007).
 */
final class WorkoutStartInput extends CapabilityData
{
    public function __construct(
        public readonly string $workout_id,
    ) {}

    public static function rules(): array
    {
        return ['workout_id' => ['required', 'ulid']];
    }
}
