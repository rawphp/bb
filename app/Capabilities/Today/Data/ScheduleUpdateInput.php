<?php

namespace App\Capabilities\Today\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Edit the eating window, meal count, busy periods and training time for this day or every future day (SCH-037, SCH-045). */
final class ScheduleUpdateInput extends CapabilityData
{
    /**
     * @param  list<ScheduleBusyPeriod>  $busy_periods
     */
    public function __construct(
        #[Field(format: 'date')] public readonly string $date,
        #[Field(enum: ['this_day', 'all_future_days'])] public readonly string $apply_to,
        #[Field(description: '24-hour "HH:MM".')] public readonly string $window_start,
        public readonly string $window_end,
        public readonly int $meal_count,
        #[Field(items: ScheduleBusyPeriod::class)] public readonly array $busy_periods,
        public readonly ?string $training_start = null,
    ) {}

    public static function rules(): array
    {
        return [
            'date' => ['date_format:Y-m-d'],
            'window_start' => ['date_format:H:i'],
            'window_end' => ['date_format:H:i'],
            'training_start' => ['nullable', 'date_format:H:i'],
        ];
    }
}
