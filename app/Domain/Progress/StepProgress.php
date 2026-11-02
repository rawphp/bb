<?php

namespace App\Domain\Progress;

use App\Domain\Model\StepPlanDay;

/** ANA-005: steps so far today against today's target range. */
final readonly class StepProgress
{
    public function __construct(
        public int $actual,
        public int $targetMin,
        public int $targetMax,
    ) {}

    public function state(): StepProgressState
    {
        return match (true) {
            $this->actual < $this->targetMin => StepProgressState::BelowRange,
            $this->actual > $this->targetMax => StepProgressState::AboveRange,
            default => StepProgressState::WithinRange,
        };
    }

    /** Steps as a share of the top of the range, at most 1. */
    public function fraction(): float
    {
        return $this->targetMax > 0 ? min(1, $this->actual / $this->targetMax) : 1;
    }

    /**
     * null (the section is hidden) without step data or without a range for $weekday (ISO).
     *
     * @param  list<StepPlanDay>  $plan
     */
    public static function make(?int $actual, array $plan, int $weekday): ?self
    {
        if ($actual === null) {
            return null;
        }
        foreach ($plan as $day) {
            if ($day->weekday === $weekday) {
                return new self($actual, $day->min, $day->max);
            }
        }

        return null;
    }
}
