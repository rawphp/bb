<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One day in a week's column (MESO-040, MESO-045). `can_open` is false for a day
 * dropped by an early end (MESO-043); `week` (the column) and `day` open workout_show.
 */
final class MesocycleDay extends CapabilityData
{
    /**
     * @param  list<MesocycleDayExercise>  $exercises
     */
    public function __construct(
        public readonly int $day,
        public readonly string $title,
        public readonly string $label,
        #[Field(enum: ['upcoming', 'started', 'done', 'skipped', 'missed', 'not_planned'])]
        public readonly string $state,
        public readonly bool $can_open,
        #[Field(items: MesocycleDayExercise::class)]
        public readonly array $exercises,
    ) {}
}
