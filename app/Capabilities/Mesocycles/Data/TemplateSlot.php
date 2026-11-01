<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A template slot: exercise, muscle and "3 sets" (MESO-012).
 */
final class TemplateSlot extends CapabilityData
{
    public function __construct(
        public readonly string $exercise_name,
        public readonly string $muscle_title,
        public readonly string $sets_text,
    ) {}
}
