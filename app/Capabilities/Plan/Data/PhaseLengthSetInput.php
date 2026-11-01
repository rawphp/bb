<?php

namespace App\Capabilities\Plan\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Lengthen or shorten a phase (PH-006) to one of `phase_show`'s lengths;
 * `weeks` null is no end (PH-005). `fix` is how the user settled an end that
 * falls mid-mesocycle (PLAN-021); `keep_end` when there was nothing to settle.
 * Also accepts PLAN-028's move to the event week. Later phases move with it.
 */
final class PhaseLengthSetInput extends CapabilityData
{
    public function __construct(
        public readonly string $phase_id,
        #[Field(minimum: 1)]
        public readonly ?int $weeks,
        #[Field(enum: ['keep_end', 'move_end_to_deload', 'change_mesocycle_length'])]
        public readonly string $fix,
    ) {}
}
