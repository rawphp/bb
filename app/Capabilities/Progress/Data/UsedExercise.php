<?php

namespace App\Capabilities\Progress\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** An exercise with a logged set, opening its history (ANA-009). */
final class UsedExercise extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $muscle,
    ) {}
}
