<?php

namespace App\Domain\Phases;

use App\Domain\Defaults\TBL012TrainingDefaults\Column;
use App\Domain\Defaults\TBL012TrainingDefaults\Row;
use App\Domain\Model\Priority;

/** The TBL-012 defaults a new mesocycle starts from. */
final readonly class MesocycleDefaults
{
    /** @param  array<string, Priority>  $priorities  keyed by MuscleGroup value */
    public function __construct(
        public Column $column,
        public Row $row,
        public array $priorities,
    ) {}
}
