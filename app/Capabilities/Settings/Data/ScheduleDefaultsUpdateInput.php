<?php

namespace App\Capabilities\Settings\Data;

use Illuminate\Validation\Rule;
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

    /**
     * An end time before its start is a window that crosses midnight (SCH-045),
     * so the times are checked for format only, never for order.
     */
    public static function rules(): array
    {
        return [
            'eating_window_start' => [Rule::date()->format('H:i')],
            'eating_window_end' => [Rule::date()->format('H:i')],
            'training_days.*.start' => [Rule::date()->format('H:i')],
            'busy_periods.*.start' => [Rule::date()->format('H:i')],
            'busy_periods.*.end' => [Rule::date()->format('H:i')],
            'step_plan.*.max' => ['gte:step_plan.*.min'],
        ];
    }
}
