<?php

namespace App\Capabilities\Workout\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Delete a set (WO-020). Removed sets drop from future sessions (PROG-019).
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
