<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One group of the Mesocycles list, in order current, planned, past (MESO-001).
 */
final class TrainMesocycleSection extends CapabilityData
{
    /**
     * @param  list<TrainMesocycleRow>  $rows
     */
    public function __construct(
        #[Field(enum: ['current', 'planned', 'past'])]
        public readonly string $status,
        public readonly string $title,
        #[Field(items: TrainMesocycleRow::class)]
        public readonly array $rows,
    ) {}
}
