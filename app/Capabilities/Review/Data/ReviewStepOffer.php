<?php

namespace App\Capabilities\Review\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** REV-050: update the step plan to the walked average on save. */
final class ReviewStepOffer extends CapabilityData
{
    public function __construct(
        public readonly string $label,
        public readonly bool $accepted,
    ) {}
}
