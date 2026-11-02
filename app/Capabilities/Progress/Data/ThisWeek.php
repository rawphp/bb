<?php

namespace App\Capabilities\Progress\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The "This week" card: calories against target, the week strip and Edit schedule (ANA-003). */
final class ThisWeek extends CapabilityData
{
    /** @param  list<ThisWeekDay>  $days */
    public function __construct(
        public readonly string $title,
        #[Field(description: 'Null when the week has no calorie target (SAFE-003).')]
        public readonly ?ProgressBar $calories,
        #[Field(items: ThisWeekDay::class)]
        public readonly array $days,
        public readonly string $edit_schedule_title,
    ) {}
}
