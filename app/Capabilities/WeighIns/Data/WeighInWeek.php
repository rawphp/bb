<?php

namespace App\Capabilities\WeighIns\Data;

use App\Capabilities\Support\Status;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** WT-008: the header over one week of the Weigh-ins list. */
final class WeighInWeek extends CapabilityData
{
    public function __construct(
        #[Field(description: 'The week\'s first day; each row\'s week names it.', format: 'date')]
        public readonly string $week,
        #[Field(description: '"This week", or the week\'s days: "24 – 30 Oct".')]
        public readonly string $title,
        #[Field(description: '"Trend 81.8 kg": the trend at the week\'s end, or today for this week; empty without one.')]
        public readonly string $trend_label,
        #[Field(description: '"+0.3 kg on last week": the change in trend from the week before; null for the first week of weigh-ins.')]
        public readonly ?Status $change,
    ) {}
}
