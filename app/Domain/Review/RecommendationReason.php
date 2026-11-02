<?php

namespace App\Domain\Review;

use App\Domain\Macros\TargetRefusal;

/**
 * Why the review recommends what it does, for the UI. A kind plus the payload of the kinds that carry one:
 * loggedDays and averageKcal for PoorAdherence, stall for Stalled, refusal for Safety.
 */
final readonly class RecommendationReason
{
    private function __construct(
        public RecommendationReasonKind $kind,
        public ?int $loggedDays = null,
        public ?float $averageKcal = null,
        public ?StallOptions $stall = null,
        public ?TargetRefusal $refusal = null,
    ) {}

    public static function towardTargetRate(): self
    {
        return new self(RecommendationReasonKind::TowardTargetRate);
    }

    public static function changeTooSmall(): self
    {
        return new self(RecommendationReasonKind::ChangeTooSmall);
    }

    public static function onTarget(): self
    {
        return new self(RecommendationReasonKind::OnTarget);
    }

    public static function poorAdherence(int $loggedDays, ?float $averageKcal): self
    {
        return new self(RecommendationReasonKind::PoorAdherence, loggedDays: $loggedDays, averageKcal: $averageKcal);
    }

    public static function waterWeight(): self
    {
        return new self(RecommendationReasonKind::WaterWeight);
    }

    public static function stalled(StallOptions $options): self
    {
        return new self(RecommendationReasonKind::Stalled, stall: $options);
    }

    public static function gainStalled(): self
    {
        return new self(RecommendationReasonKind::GainStalled);
    }

    public static function gainTooFast(): self
    {
        return new self(RecommendationReasonKind::GainTooFast);
    }

    public static function insideCorridor(): self
    {
        return new self(RecommendationReasonKind::InsideCorridor);
    }

    public static function outsideCorridor(): self
    {
        return new self(RecommendationReasonKind::OutsideCorridor);
    }

    public static function returnFromBreak(): self
    {
        return new self(RecommendationReasonKind::ReturnFromBreak);
    }

    public static function firstWeekOfPhase(): self
    {
        return new self(RecommendationReasonKind::FirstWeekOfPhase);
    }

    public static function noTrend(): self
    {
        return new self(RecommendationReasonKind::NoTrend);
    }

    public static function safety(TargetRefusal $refusal): self
    {
        return new self(RecommendationReasonKind::Safety, refusal: $refusal);
    }

    /** The spec's own messages (REV-026, REV-027); other reasons have no fixed copy. */
    public function message(): ?string
    {
        return match ($this->kind) {
            RecommendationReasonKind::WaterWeight => 'Water weight is up. Holding your target this week.',
            RecommendationReasonKind::Stalled => 'Your progress has stalled',
            default => null,
        };
    }
}
