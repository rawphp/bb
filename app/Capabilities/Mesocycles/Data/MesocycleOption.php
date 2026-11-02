<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A value a stepper may take, with its words ("5 weeks", "2 sets"). The server
 * sends only the allowed values (MESO-004, TBL-020, MESO-014, MESO-020), so the client clamps nothing.
 */
final class MesocycleOption extends CapabilityData
{
    public function __construct(
        public readonly int $value,
        public readonly string $text,
    ) {}
}
