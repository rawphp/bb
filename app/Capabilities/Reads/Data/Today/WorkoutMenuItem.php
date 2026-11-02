<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** An entry of the workout card's menu (SCH-050, SCH-051). */
final class WorkoutMenuItem extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['change_time', 'move_day'])] public readonly string $action,
        public readonly string $label,
    ) {}
}
