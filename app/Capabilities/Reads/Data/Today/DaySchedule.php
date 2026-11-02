<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** What Edit schedule and Add busy period open with (SCH-033, SCH-037, SCH-039). */
final class DaySchedule extends CapabilityData
{
    /**
     * @param  list<ApplyToChoice>  $apply_to
     * @param  list<WeekdayChip>  $training_weekdays
     */
    public function __construct(
        #[Field(items: ApplyToChoice::class)] public readonly array $apply_to,
        public readonly ScheduleValues $this_day,
        public readonly ScheduleValues $all_future_days,
        #[Field(items: WeekdayChip::class)] public readonly array $training_weekdays,
    ) {}
}
