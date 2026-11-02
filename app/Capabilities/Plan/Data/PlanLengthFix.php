<?php

namespace App\Capabilities\Plan\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * PLAN-021: a way to settle a phase end that falls mid-mesocycle: move the end
 * to the nearest deload (within 2 weeks), change the mesocycle's length, or
 * keep the end as chosen. Only fixes that fit are listed.
 */
final class PlanLengthFix extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['move_end_to_deload', 'change_mesocycle_length', 'keep_end'])]
        public readonly string $fix,
        public readonly string $text,
    ) {}
}
