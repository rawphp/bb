<?php

namespace App\Domain\Defaults\TBL040LoadSteps;

use App\Domain\Model\WeightUnit;

/** A TBL-040 step. Null kg and lb mean reps only; null micro-plate steps mean the table gives none. */
final readonly class Step
{
    public function __construct(
        public ?float $kg,
        public ?float $lb,
        public ?float $microPlatesKg,
        public ?float $microPlatesLb,
    ) {}

    /**
     * The step in a unit; null for reps only. With micro plates on, the micro step where the table gives one,
     * otherwise the regular step.
     */
    public function value(WeightUnit $unit, bool $microPlates): ?float
    {
        $regular = $unit === WeightUnit::Kg ? $this->kg : $this->lb;
        $micro = $unit === WeightUnit::Kg ? $this->microPlatesKg : $this->microPlatesLb;

        return $microPlates && $micro !== null ? $micro : $regular;
    }
}
