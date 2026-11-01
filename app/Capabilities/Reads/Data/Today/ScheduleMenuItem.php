<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** An entry of the schedule menu (SCH-030), in order; a past day offers fewer (NUT-024). */
final class ScheduleMenuItem extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['add_meal', 'add_workout', 'add_busy_period', 'copy_day', 'copy_week', 'optimize_schedule', 'edit_schedule'])] public readonly string $action,
        public readonly string $label,
    ) {}
}
