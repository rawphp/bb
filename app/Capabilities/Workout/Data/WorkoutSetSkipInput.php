<?php

namespace App\Capabilities\Workout\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Skip or unskip a set (WO-020). A skipped set stays greyed and never counts as
 * a working set (WO-025).
 */
final class WorkoutSetSkipInput extends CapabilityData
{
    public function __construct(
        public readonly string $set_id,
        public readonly bool $is_skipped,
    ) {}

    public static function rules(): array
    {
        return ['set_id' => ['required', 'ulid']];
    }
}
