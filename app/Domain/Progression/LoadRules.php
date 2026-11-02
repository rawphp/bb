<?php

namespace App\Domain\Progression;

use App\Domain\Defaults\TBL040LoadSteps;
use App\Domain\Defaults\TBL040LoadSteps\LoadType;
use App\Domain\Model\ClosedRange;
use App\Domain\Model\WeightUnit;

/** What the load maths needs to know about one exercise. Every weight is in $unit. */
final readonly class LoadRules
{
    /**
     * @param  ?float  $stepOverride  EX-012: per-exercise step, in $unit
     * @param  ClosedRange  $repRange  whole reps
     * @param  ?float  $trendBodyweight  EX-006: trend weight in $unit, the base load of bodyweight exercises
     */
    public function __construct(
        public LoadType $loadType,
        public WeightUnit $unit,
        public bool $microPlates,
        public ?float $stepOverride,
        public ClosedRange $repRange,
        public ?float $trendBodyweight = null,
    ) {}

    /**
     * Weight step, or null for reps only. Fat loss takes the smallest increment the equipment allows (PROG-023);
     * micro plates do the same when turned on (TBL-040). An override of 0 or less has no weight to step to, so it
     * means reps only too, and no caller divides by it.
     */
    public function step(bool $isFatLoss): ?float
    {
        if ($this->loadType === LoadType::Bodyweight) {
            return null;
        }
        if ($this->stepOverride !== null) {
            return $this->stepOverride > 0 ? $this->stepOverride : null;
        }

        return TBL040LoadSteps::step($this->loadType)->value($this->unit, $this->microPlates || $isFatLoss);
    }

    /** EX-006: the body counts as load for bodyweight exercises. */
    public function baseLoad(): float
    {
        return match ($this->loadType) {
            LoadType::Bodyweight, LoadType::BodyweightPlusLoad => $this->trendBodyweight ?? 0.0,
            LoadType::BarbellOrSmithMachine, LoadType::Dumbbell, LoadType::MachineOrCable => 0.0,
        };
    }
}
