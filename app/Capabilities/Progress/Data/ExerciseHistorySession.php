<?php

namespace App\Capabilities\Progress\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** One session of an exercise with its sets (ANA-009). */
final class ExerciseHistorySession extends CapabilityData
{
    /** @param  list<ExerciseHistorySet>  $sets */
    public function __construct(
        public readonly string $id,
        public readonly string $date_label,
        #[Field(description: '"Best e1RM 76.0 kg".')]
        public readonly string $best_label,
        #[Field(items: ExerciseHistorySet::class)]
        public readonly array $sets,
    ) {}
}
