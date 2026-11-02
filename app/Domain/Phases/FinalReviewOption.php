<?php

namespace App\Domain\Phases;

/** PH-041: what the final weekly review offers. */
final readonly class FinalReviewOption
{
    /** @param  ?int  $maxExtraWeeks  Keep goal only: extend by at most this many weeks; null = no limit */
    private function __construct(
        public FinalReviewChoice $choice,
        public ?int $maxExtraWeeks = null,
    ) {}

    public static function keepGoal(?int $maxExtraWeeks): self
    {
        return new self(FinalReviewChoice::KeepGoal, $maxExtraWeeks);
    }

    public static function chooseNewGoal(): self
    {
        return new self(FinalReviewChoice::ChooseNewGoal);
    }

    public static function endPlan(): self
    {
        return new self(FinalReviewChoice::EndPlan);
    }
}
