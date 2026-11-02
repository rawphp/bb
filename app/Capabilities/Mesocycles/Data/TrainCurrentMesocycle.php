<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * The running mesocycle's card (CurrentMesocycleView). `next_mesocycle` is set during
 * its deload week (MESO-060).
 */
final class TrainCurrentMesocycle extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $week_text,
        public readonly string $phase_title,
        public readonly string $dates_text,
        public readonly ?TrainNextMesocycle $next_mesocycle,
    ) {}
}
