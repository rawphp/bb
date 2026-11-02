<?php

namespace App\Capabilities\Exercises\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * The exercise library (EX-001, EX-003, EX-013): every exercise the user can
 * pick, sorted by name, plus the muscle group, equipment and load type choices
 * for the filters and the custom exercise sheet (EX-004, EX-005).
 */
final class ExercisesShowOutput extends CapabilityData
{
    /**
     * @param  list<ExerciseLibraryRow>  $exercises
     * @param  list<ExerciseChoice>  $muscle_groups
     * @param  list<ExerciseChoice>  $equipment
     * @param  list<ExerciseChoice>  $load_types
     */
    public function __construct(
        #[Field(items: ExerciseLibraryRow::class)]
        public readonly array $exercises,
        #[Field(items: ExerciseChoice::class)]
        public readonly array $muscle_groups,
        #[Field(items: ExerciseChoice::class)]
        public readonly array $equipment,
        #[Field(items: ExerciseChoice::class)]
        public readonly array $load_types,
    ) {}
}
