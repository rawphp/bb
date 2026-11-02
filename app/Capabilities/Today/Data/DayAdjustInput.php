<?php

namespace App\Capabilities\Today\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Move calories between meals of the day or between days of the week (DAY-020, NUT-021). */
final class DayAdjustInput extends CapabilityData
{
    public function __construct(
        #[Field(format: 'date')] public readonly string $date,
        #[Field(enum: ['meals', 'days'])] public readonly string $mode,
        public readonly int $kcal,
        #[Field(description: 'Required when mode is meals.')] public readonly ?string $from_meal_id = null,
        #[Field(description: 'Required when mode is meals.')] public readonly ?string $to_meal_id = null,
        #[Field(description: 'Required when mode is days.', format: 'date')] public readonly ?string $other_date = null,
        #[Field(enum: ['give', 'take'])] public readonly string $direction = 'give',
    ) {}

    public static function rules(): array
    {
        return [
            'from_meal_id' => ['required_if:mode,meals'],
            'to_meal_id' => ['required_if:mode,meals'],
            'other_date' => ['required_if:mode,days'],
        ];
    }
}
