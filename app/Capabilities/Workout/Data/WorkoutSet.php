<?php

namespace App\Capabilities\Workout\Data;

use App\Capabilities\Train\Data\Measure;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One set row (WO-003 to WO-007, WO-021). `weight` and `reps` are what the
 * fields hold: the logged values, else the targets (WO-004).
 *
 * CAP-016: when the user types a weight, the view looks it up in `rep_table`
 * and shows that row's reps; a weight not in the table shows
 * `off_table_text` (PROG-025). The target icon shows while the weight equals
 * `target_weight`. The view does no arithmetic.
 */
final class WorkoutSet extends CapabilityData
{
    /**
     * @param  list<WorkoutRepRow>  $rep_table
     */
    public function __construct(
        public readonly string $id,
        public readonly int $number,
        #[Field(enum: ['regular_straight', 'regular_down', 'regular_ascending', 'myorep', 'myorep_match'])]
        public readonly string $set_type,
        /** WO-021 "M" or "MM"; null for a regular set. */
        public readonly ?string $badge,
        public readonly ?Measure $weight,
        public readonly ?int $reps,
        /** PROG-020; null with no planned load (WO-006). */
        public readonly ?Measure $target_weight,
        /** WO-007 "82.5 kg × 10"; null with no target. */
        public readonly ?string $target_text,
        /** WO-006 "3 RIR" or "Reps", for an empty reps field. */
        public readonly string $reps_placeholder,
        #[Field(items: WorkoutRepRow::class)]
        public readonly array $rep_table,
        /** PROG-025 "2 RIR, just log reps"; null when the set has no table. */
        public readonly ?string $off_table_text,
        public readonly bool $is_logged,
        public readonly bool $is_skipped,
    ) {}
}
