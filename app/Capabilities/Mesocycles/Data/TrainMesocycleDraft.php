<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * The unfinished build (MESO-007) and the warning shown before another build replaces it.
 */
final class TrainMesocycleDraft extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $text,
        public readonly string $replace_warning,
    ) {}
}
