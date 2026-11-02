<?php

namespace App\Capabilities\Plan\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * PLAN-028: the phase's last week is not its event's week, so the screen
 * offers to move the end there. Accepting sends `phase_length_set` with
 * `weeks`.
 */
final class PhaseEventOffer extends CapabilityData
{
    public function __construct(
        public readonly string $text,
        public readonly int $weeks,
    ) {}
}
