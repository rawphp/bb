<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A way to build the next mesocycle (MESO-060). */
final class NextMesocycleOption extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['copy_previous', 'template', 'builder'])] public readonly string $route,
        public readonly string $label,
    ) {}
}
