<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Support\CapabilityData;

/** A meal of the day as the move would leave it. */
final class AdjustProjectedMeal extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $kcal_text,
        public readonly string $protein_text,
    ) {}
}
