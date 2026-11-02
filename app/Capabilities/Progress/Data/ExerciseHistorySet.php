<?php

namespace App\Capabilities\Progress\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** One set of a session: "60.0 kg × 8" (ANA-009). */
final class ExerciseHistorySet extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        #[Field(description: 'The first set that reached the session\'s best estimated 1RM; it shows the `best` pill.')]
        public readonly bool $is_best,
    ) {}
}
