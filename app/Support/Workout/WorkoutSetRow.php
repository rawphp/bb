<?php

namespace App\Support\Workout;

use App\Domain\Progression\WorkingSet;
use App\Domain\Progression\WorkoutSetType;

/** One set of a workout, planned or stored: its frozen targets and what was typed or lifted, in kg. */
final readonly class WorkoutSetRow
{
    public function __construct(
        public string $id,
        public WorkoutSetType $type,
        public ?float $targetKg,
        public ?int $targetReps,
        public ?float $actualKg,
        public ?int $actualReps,
        public bool $isLogged,
        public bool $isSkipped,
    ) {}

    /** WO-040: logged or skipped. */
    public function isDone(): bool
    {
        return $this->isLogged || $this->isSkipped;
    }

    /** WO-025. */
    public function isWorking(): bool
    {
        return WorkingSet::counts($this->isLogged, $this->isSkipped);
    }
}
