<?php

namespace App\Capabilities\Review\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** The field for "Choose my own calories", with the amount last answered. */
final class ReviewOwnCalories extends CapabilityData
{
    public function __construct(
        public readonly string $label,
        public readonly string $unit_label,
        public readonly ?int $value,
    ) {}
}
