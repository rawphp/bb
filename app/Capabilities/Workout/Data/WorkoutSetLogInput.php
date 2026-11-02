<?php

namespace App\Capabilities\Workout\Data;

use App\Capabilities\Support\ExplicitNulls;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Save a set's fields and tick or untick Log (WO-003, WO-025, OFF-021 "log a
 * set", "edit a set"). `weight` is one dumbbell's weight for dumbbells
 * (WO-023); for bodyweight it is the added weight, or null for none. It is
 * stored in kg for every load type (CAP-015).
 */
final class WorkoutSetLogInput extends CapabilityData
{
    use ExplicitNulls;

    public function __construct(
        public readonly string $set_id,
        public readonly ?WorkoutWeight $weight,
        #[Field(minimum: 0)]
        public readonly ?int $reps,
        public readonly bool $is_logged,
    ) {}

    public static function rules(): array
    {
        return ['set_id' => ['ulid']];
    }
}
