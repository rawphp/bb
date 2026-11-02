<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Weekly sets for one muscle in a template (MESO-012).
 */
final class TemplateWeeklySets extends CapabilityData
{
    public function __construct(
        public readonly string $muscle_title,
        public readonly string $sets_text,
    ) {}
}
