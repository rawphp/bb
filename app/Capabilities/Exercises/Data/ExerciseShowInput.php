<?php

namespace App\Capabilities\Exercises\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Which exercise to show (EX-002).
 */
final class ExerciseShowInput extends CapabilityData
{
    public function __construct(
        public readonly string $exercise_id,
    ) {}
}
