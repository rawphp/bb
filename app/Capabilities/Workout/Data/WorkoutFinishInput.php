<?php

namespace App\Capabilities\Workout\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Finish the session (WO-010, WO-040): records the end time, then works out next
 * week's sets from the feedback (PROG-010 to PROG-019). Refused while a set is
 * neither logged nor skipped or feedback is unanswered. The toast and undo come
 * back on the WriteResult (UI-010).
 */
final class WorkoutFinishInput extends CapabilityData
{
    public function __construct(
        public readonly string $workout_id,
    ) {}

    public static function rules(): array
    {
        return ['workout_id' => ['ulid']];
    }
}
