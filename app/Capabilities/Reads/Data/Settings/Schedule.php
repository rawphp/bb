<?php

namespace App\Capabilities\Reads\Data\Settings;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Schedule defaults (SET-005) and the limits the form allows. */
final class Schedule extends CapabilityData
{
    /**
     * @param  list<Weekday>  $weekdays
     * @param  list<TrainingDay>  $training_days
     * @param  list<BusyPeriod>  $busy_periods
     * @param  list<StepRange>  $step_plan
     */
    public function __construct(
        #[Field(description: '24-hour time, HH:mm')]
        public readonly string $eating_window_start,
        #[Field(description: '24-hour time, HH:mm')]
        public readonly string $eating_window_end,
        public readonly int $meal_count,
        public readonly int $meal_count_min,
        public readonly int $meal_count_max,
        #[Field(description: 'all seven weekdays, Monday first', items: Weekday::class)]
        public readonly array $weekdays,
        #[Field(items: TrainingDay::class)]
        public readonly array $training_days,
        #[Field(description: '24-hour time, HH:mm')]
        public readonly string $new_training_day_start,
        public readonly int $new_training_day_minutes,
        public readonly int $session_minutes_min,
        public readonly int $session_minutes_max,
        public readonly int $session_minutes_step,
        #[Field(items: BusyPeriod::class)]
        public readonly array $busy_periods,
        public readonly BusyPeriod $new_busy_period,
        #[Field(description: 'all seven weekdays, Monday first', items: StepRange::class)]
        public readonly array $step_plan,
        #[Field(description: 'null until there is a plan', minimum: 1, maximum: 7)]
        public readonly ?int $review_weekday,
    ) {}
}
