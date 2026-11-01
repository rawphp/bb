<?php

namespace App\Capabilities\WeighIns\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** One entry of the schedule: weekdays with a reminder time (WT-001, WT-007). */
final class WeighInScheduleEntry extends CapabilityData
{
    /** @param  list<int>  $weekdays */
    public function __construct(
        #[Field(description: 'ISO weekdays, 1 is Monday.')]
        public readonly array $weekdays,
        #[Field(description: '"Mon, Wed, Fri" or "Every day".')]
        public readonly string $days_label,
        #[Field(description: '"07:00", as weigh_in_schedule_set takes it.')]
        public readonly string $time,
        public readonly string $time_label,
        #[Field(description: 'False when deleting it leaves fewer than 2 weigh-in days a week (WT-007).')]
        public readonly bool $can_delete,
    ) {}
}
