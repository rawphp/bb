<?php

namespace App\Capabilities\Today\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Move a planned session to another day of its plan week (SCH-032, SCH-038, SCH-051). */
final class WorkoutMoveInput extends CapabilityData
{
    public function __construct(
        public readonly string $mesocycle_id,
        public readonly int $week,
        public readonly int $day,
        #[Field(description: 'The day it moves to.', format: 'date')] public readonly string $date,
    ) {}
}
