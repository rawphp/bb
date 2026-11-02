<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Today's session on the Train tab (NAV-002): its title and the ref that opens it.
 */
final class TrainTodaySession extends CapabilityData
{
    public function __construct(
        public readonly string $title,
        public readonly string $mesocycle_id,
        public readonly int $week,
        public readonly int $day,
    ) {}
}
