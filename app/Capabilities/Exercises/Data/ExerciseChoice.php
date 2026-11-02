<?php

namespace App\Capabilities\Exercises\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One option in a library filter or custom exercise picker (EX-003, EX-004):
 * the value to filter on or send back, and its label ("Front delts").
 */
final class ExerciseChoice extends CapabilityData
{
    public function __construct(
        public readonly string $value,
        public readonly string $label,
    ) {}
}
