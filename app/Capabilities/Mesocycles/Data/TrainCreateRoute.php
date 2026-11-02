<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One way to create a mesocycle on the + menu (MESO-002). Copy is unavailable with
 * nothing to copy; it names the mesocycle it copies and the MESO-065 notice when priorities reset.
 */
final class TrainCreateRoute extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['template', 'copy_previous', 'builder', 'scratch'])]
        public readonly string $route,
        public readonly string $title,
        public readonly bool $available,
        public readonly ?string $copy_source_id,
        public readonly ?string $priority_notice,
    ) {}
}
