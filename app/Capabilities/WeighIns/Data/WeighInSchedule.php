<?php

namespace App\Capabilities\WeighIns\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The weigh-in schedule and what its sheet needs (WT-001, WT-007). */
final class WeighInSchedule extends CapabilityData
{
    /**
     * @param  list<WeighInScheduleEntry>  $entries
     * @param  list<Weekday>  $weekdays
     */
    public function __construct(
        #[Field(description: '"Schedule: 4 days a week".')]
        public readonly string $summary,
        #[Field(description: 'The time only sets the reminder (WT-001).')]
        public readonly string $note,
        #[Field(items: WeighInScheduleEntry::class)]
        public readonly array $entries,
        #[Field(items: Weekday::class)]
        public readonly array $weekdays,
        #[Field(description: 'Shown on an entry whose can_delete is false.')]
        public readonly string $delete_blocked_message,
    ) {}
}
