<?php

namespace App\Capabilities\Plan\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A deload week on the timeline and its DeloadWeekSheet (PLAN-003, PLAN-022 to
 * PLAN-024). `marker` is what the timeline draws: a deload at maintenance
 * calories, or a deficit deload after the user turned its diet break off
 * (PLAN-023). `diet_break_action` is what the sheet offers: turn the diet break
 * off (after `PlanShowOutput::diet_break_off_warning`), turn it back on, or
 * nothing (past weeks, Muscle gain, Maintenance, training-only).
 * `start_date` is the key `plan_diet_break_set` sends back.
 */
final class PlanDeloadWeek extends CapabilityData
{
    public function __construct(
        public readonly int $week,
        public readonly string $start_date,
        public readonly string $dates_text,
        public readonly string $label,
        #[Field(enum: ['deload', 'deficit_deload'])]
        public readonly string $marker,
        #[Field(enum: ['none', 'turn_off', 'turn_on'])]
        public readonly string $diet_break_action,
    ) {}
}
