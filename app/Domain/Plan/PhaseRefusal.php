<?php

namespace App\Domain\Plan;

use App\Domain\Model\ClosedRange;
use App\Domain\Plan\PhaseRefusal\Reason;

/** Why a phase was refused, with the limit it broke (PH-003 to PH-006, TBL-010, SAFE-006, SAFE-007, PLAN-006, PLAN-008). */
final readonly class PhaseRefusal
{
    private function __construct(
        public Reason $reason,
        public ?int $minimumWeeks = null,
        public ?int $maximumWeeks = null,
        public ?ClosedRange $rateRange = null,
    ) {}

    public static function endDateRequired(): self
    {
        return new self(Reason::EndDateRequired);
    }

    public static function tooShort(int $minimumWeeks): self
    {
        return new self(Reason::TooShort, minimumWeeks: $minimumWeeks);
    }

    public static function tooLong(int $maximumWeeks): self
    {
        return new self(Reason::TooLong, maximumWeeks: $maximumWeeks);
    }

    public static function rateOutOfRange(ClosedRange $range): self
    {
        return new self(Reason::RateOutOfRange, rateRange: $range);
    }

    public static function notOnWeekBoundary(): self
    {
        return new self(Reason::NotOnWeekBoundary);
    }

    public static function previousPhaseHasNoEnd(): self
    {
        return new self(Reason::PreviousPhaseHasNoEnd);
    }
}
