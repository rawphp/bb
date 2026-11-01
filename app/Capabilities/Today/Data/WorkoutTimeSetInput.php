<?php

namespace App\Capabilities\Today\Data;

use Illuminate\Validation\Rule;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Set a planned session's start time (SCH-050). */
final class WorkoutTimeSetInput extends CapabilityData
{
    public function __construct(
        public readonly string $mesocycle_id,
        public readonly int $week,
        public readonly int $day,
        #[Field(description: '24-hour "HH:MM".')] public readonly string $time,
    ) {}

    public static function rules(): array
    {
        return [
            'time' => [Rule::date()->format('H:i')],
        ];
    }
}
