<?php

namespace App\Domain\Onboarding;

/** Where setup is in the steps shown (UI-007). Steps count from 1; the review counts as the last step. */
final readonly class StepPosition
{
    public function __construct(
        public int $current,
        public int $total,
        public bool $isReview = false,
    ) {}

    /** "Step 4 of 13", or "Review". */
    public function counter(): string
    {
        return $this->isReview ? 'Review' : "Step {$this->current} of {$this->total}";
    }

    public function canGoBack(): bool
    {
        return $this->current > 1;
    }
}
