<?php

namespace App\Domain\Review;

/** Whether the weekly review can be done, and for which weeks. Build one with unavailable(), available() or done(). */
final readonly class ReviewStatus
{
    private function __construct(
        public ReviewStatusKind $kind,
        private ?ReviewPeriod $period,
    ) {}

    public static function unavailable(): self
    {
        return new self(ReviewStatusKind::Unavailable, null);
    }

    public static function available(ReviewPeriod $period): self
    {
        return new self(ReviewStatusKind::Available, $period);
    }

    public static function done(ReviewPeriod $period): self
    {
        return new self(ReviewStatusKind::Done, $period);
    }

    /** The weeks the review is about; null when it is unavailable. */
    public function period(): ?ReviewPeriod
    {
        return $this->period;
    }

    public function isAvailable(): bool
    {
        return $this->kind === ReviewStatusKind::Available;
    }

    public function isDone(): bool
    {
        return $this->kind === ReviewStatusKind::Done;
    }
}
