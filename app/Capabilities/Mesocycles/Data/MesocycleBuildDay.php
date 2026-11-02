<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A draft day as the user edited it; the server labels it (MESO-005).
 */
final class MesocycleBuildDay extends CapabilityData
{
    /**
     * @param  list<MesocycleBuildSlot>  $slots
     */
    public function __construct(
        public readonly string $day_id,
        #[Field(items: MesocycleBuildSlot::class)]
        public readonly array $slots,
    ) {}
}
