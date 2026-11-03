<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Changes a running mesocycle's length, the deload staying last (MESO-042). `expected_weeks` is the length the user
 * saw; the change is refused when the mesocycle's length has moved since.
 */
final class MesocycleLengthSetInput extends CapabilityData
{
    public function __construct(
        public readonly string $mesocycle_id,
        #[Field(minimum: 1, maximum: 8)]
        public readonly int $weeks,
        #[Field(description: 'The weeks_count mesocycle_show last gave; refused when the length has moved since', minimum: 1, maximum: 8)]
        public readonly int $expected_weeks,
    ) {}
}
