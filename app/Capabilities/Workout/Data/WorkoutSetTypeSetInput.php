<?php

namespace App\Capabilities\Workout\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Change a set's type (WO-020, WO-021): Regular (Straight, Down, Ascending),
 * Myorep or Myorep match.
 */
final class WorkoutSetTypeSetInput extends CapabilityData
{
    public function __construct(
        public readonly string $set_id,
        #[Field(enum: ['regular_straight', 'regular_down', 'regular_ascending', 'myorep', 'myorep_match'])]
        public readonly string $set_type,
    ) {}

    public static function rules(): array
    {
        return ['set_id' => ['ulid']];
    }
}
