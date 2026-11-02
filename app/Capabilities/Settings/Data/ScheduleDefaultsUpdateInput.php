<?php

namespace App\Capabilities\Settings\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** schedule_defaults_update: the Schedule defaults form (SET-005). */
final class ScheduleDefaultsUpdateInput extends CapabilityData
{
    /**
     * @param  list<TrainingDayInput>  $training_days
     * @param  list<BusyPeriodInput>  $busy_periods
     * @param  list<StepRangeInput>  $step_plan
     */
    public function __construct(
        #[Field(description: '24-hour time, HH:mm')]
        public readonly string $eating_window_start,
        #[Field(description: '24-hour time, HH:mm')]
        public readonly string $eating_window_end,
        #[Field(minimum: 2, maximum: 6)]
        public readonly int $meal_count,
        #[Field(items: TrainingDayInput::class)]
        public readonly array $training_days,
        #[Field(items: BusyPeriodInput::class)]
        public readonly array $busy_periods,
        #[Field(items: StepRangeInput::class)]
        public readonly array $step_plan,
        #[Field(description: 'null leaves the review day as it is', minimum: 1, maximum: 7)]
        public readonly ?int $review_weekday,
    ) {}
}
