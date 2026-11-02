<?php

namespace App\Domain\Review;

/** What the review recommends about fatigue. */
final readonly class FatigueAdvice
{
    private function __construct(
        /** Null means "end the fat-loss phase" (REV-043); otherwise take Drop fatigue for this reason. */
        public ?DropFatigueReason $dropFatigue,
    ) {}

    /** REV-041, REV-042: take Drop fatigue over cutting volume. */
    public static function dropFatigue(DropFatigueReason $reason): self
    {
        return new self($reason);
    }

    /** REV-043: end the fat-loss phase and move to Maintenance. */
    public static function endFatLoss(): self
    {
        return new self(null);
    }

    /** The text the spec gives; null where it gives none. */
    public function message(): ?string
    {
        return $this->dropFatigue === DropFatigueReason::TrainingFatigue
            ? 'Your lifts are dropping. It might be the diet, not the training.'
            : null;
    }
}
