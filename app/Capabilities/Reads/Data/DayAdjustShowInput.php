<?php

namespace App\Capabilities\Reads\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The Adjust sheet as the user has filled it in so far (DAY-020). */
final class DayAdjustShowInput extends CapabilityData
{
    public function __construct(
        #[Field(format: 'date')] public readonly string $date,
        #[Field(description: 'Between meals of the day, or between days of the week.', enum: ['meals', 'days'])] public readonly string $mode = 'meals',
        public readonly ?string $from_meal_id = null,
        public readonly ?string $to_meal_id = null,
        #[Field(format: 'date')] public readonly ?string $other_date = null,
        #[Field(description: 'Between days: the day gives calories to the other day or takes them.', enum: ['give', 'take'])] public readonly string $direction = 'give',
        public readonly ?int $kcal = null,
    ) {}

    public static function rules(): array
    {
        return [
            'date' => ['date_format:Y-m-d'],
            'other_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
