<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A day of a draft, labelled by the server (MESO-005), with its slots.
 */
final class MesocycleBuildDayRow extends CapabilityData
{
    /**
     * @param  list<MesocycleBuildSlotRow>  $slots
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        #[Field(items: MesocycleBuildSlotRow::class)]
        public readonly array $slots,
    ) {}
}
