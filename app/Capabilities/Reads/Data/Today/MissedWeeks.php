<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Support\CapabilityData;

/** The missed-weeks prompt (SCH-058), with the end confirmation (MESO-043, MESO-044). */
final class MissedWeeks extends CapabilityData
{
    public function __construct(
        public readonly string $mesocycle_id,
        public readonly string $title,
        public readonly string $text,
        public readonly bool $can_restart,
        public readonly string $end_warning,
        public readonly bool $offers_diet_break,
    ) {}
}
