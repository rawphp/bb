<?php

namespace App\Capabilities\Exercises\Data;

use App\Capabilities\Support\DomainValues;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One exercise (EX-002): the Detail tab (video, muscle, equipment, load type
 * EX-005, recommended rep range EX-007, pinned note EX-010, load step EX-012)
 * and the History tab (every set ever logged, newest day first).
 *
 * `is_custom` offers Edit and Delete (EX-004); the enum values prefill the
 * custom exercise sheet. `rep_range_text` ("10–20 reps") is null unless the
 * exercise is in the current mesocycle. `load_step` is null for reps-only
 * (bodyweight) exercises.
 */
final class ExerciseShowOutput extends CapabilityData
{
    /**
     * @param  list<ExerciseHistoryDay>  $history
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly bool $is_custom,
        public readonly ?string $video_url,
        #[Field(enum: DomainValues::MUSCLE_GROUPS)]
        public readonly string $muscle_group,
        public readonly string $muscle_group_text,
        #[Field(enum: DomainValues::EQUIPMENT)]
        public readonly string $equipment,
        public readonly string $equipment_text,
        #[Field(enum: DomainValues::LOAD_TYPES)]
        public readonly string $load_type,
        public readonly string $load_type_text,
        public readonly ?string $rep_range_text,
        public readonly ?string $pinned_note,
        public readonly ?ExerciseLoadStep $load_step,
        #[Field(items: ExerciseHistoryDay::class)]
        public readonly array $history,
    ) {}
}
