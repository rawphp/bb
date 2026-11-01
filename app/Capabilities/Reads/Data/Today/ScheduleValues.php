<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Edit schedule's fields for one "Apply to" choice (SCH-037, SCH-045). */
final class ScheduleValues extends CapabilityData
{
    /**
     * @param  list<BusyPeriodValue>  $busy_periods
     */
    public function __construct(
        #[Field(description: '24-hour "HH:MM".')] public readonly string $window_start,
        public readonly string $window_end,
        public readonly int $meal_count,
        #[Field(items: BusyPeriodValue::class)] public readonly array $busy_periods,
        #[Field(description: 'null on a day without training.')] public readonly ?string $training_start,
    ) {}
}
