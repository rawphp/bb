<?php

namespace App\Capabilities\Today\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Copy a day's planned foods to other days (SCH-034, MEAL-060). */
final class DayCopyInput extends CapabilityData
{
    /**
     * @param  list<string>  $target_dates
     */
    public function __construct(
        #[Field(format: 'date')] public readonly string $source_date,
        #[Field(description: '"YYYY-MM-DD".')] public readonly array $target_dates,
        public readonly bool $update_target_macros = true,
        public readonly bool $update_meal_times = false,
        public readonly bool $copy_locked_state = false,
    ) {}

    public static function rules(): array
    {
        return [
            'source_date' => ['date_format:Y-m-d'],
            'target_dates.*' => ['date_format:Y-m-d'],
        ];
    }
}
