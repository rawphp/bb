<?php

namespace App\Capabilities\Exercises\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One workout's sets of an exercise on the History tab (EX-002):
 * "Thu, 8 Oct 2026", the sets in logged order, and "Best e1RM 107.2 kg"
 * (null when no set has a load).
 */
final class ExerciseHistoryDay extends CapabilityData
{
    /**
     * @param  list<ExerciseHistorySet>  $sets
     */
    public function __construct(
        public readonly string $workout_id,
        public readonly string $date_text,
        #[Field(items: ExerciseHistorySet::class)]
        public readonly array $sets,
        public readonly ?string $best_text,
    ) {}
}
