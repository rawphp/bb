<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One template (MESO-010, MESO-015): "2 days/week · Full body".
 */
final class TemplateRow extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $summary,
    ) {}
}
