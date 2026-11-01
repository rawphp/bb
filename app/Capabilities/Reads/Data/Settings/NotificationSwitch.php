<?php

namespace App\Capabilities\Reads\Data\Settings;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** One reminder type's switch (NOTIF-007). */
final class NotificationSwitch extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['weigh_in', 'meal', 'workout', 'weekly_review', 'next_mesocycle', 'retarget'])]
        public readonly string $kind,
        public readonly string $label,
        public readonly bool $on,
    ) {}
}
