<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One saved mesocycle (MESO-001): name, dates ("7 Sep – 11 Oct 2026") and
 * length and phase ("5 weeks · Muscle gain").
 */
final class TrainMesocycleRow extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $dates_text,
        public readonly string $summary_text,
    ) {}
}
