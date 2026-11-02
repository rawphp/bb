<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Changes a running mesocycle's length, the deload staying last (MESO-042).
 */
final class MesocycleLengthSetInput extends CapabilityData
{
    public function __construct(
        public readonly string $mesocycle_id,
        #[Field(minimum: 1, maximum: 8)]
        public readonly int $weeks,
    ) {}
}
