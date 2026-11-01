<?php

namespace App\Domain\Defaults;

use App\Domain\Model\Pump;
use App\Domain\Model\Soreness;
use App\Domain\Model\VolumeFeedback;

/** TBL-022 Feedback scores (the table lives in PROG-010). Per-priority set thresholds are rules, not encoded here. */
final class TBL022FeedbackScores
{
    /** These soreness points only count when the pump was Low or the volume was Not enough. */
    public const POSITIVE_SORENESS_COUNTING_ONLY_WITH_LOW_PUMP_OR_NOT_ENOUGH_VOLUME = [
        Soreness::NeverGotSore,
        Soreness::HealedAWhileAgo,
    ];

    public static function sorenessPoints(Soreness $soreness): float
    {
        return match ($soreness) {
            Soreness::NeverGotSore => 1.0,
            Soreness::HealedAWhileAgo => 0.5,
            Soreness::HealedJustOnTime => 0.0,
            Soreness::StillSore => -1.0,
        };
    }

    public static function pumpPoints(Pump $pump): float
    {
        return match ($pump) {
            Pump::Low => 1.0,
            Pump::Moderate => 0.0,
            Pump::Amazing => -0.5,
        };
    }

    /** Null for "Pushed my limits" and "Too much": PROG-012 and PROG-013 override the score. */
    public static function volumePoints(VolumeFeedback $volume): ?float
    {
        return match ($volume) {
            VolumeFeedback::NotEnough => 1.0,
            VolumeFeedback::JustRight => 0.0,
            VolumeFeedback::PushedMyLimits, VolumeFeedback::TooMuch => null,
        };
    }
}
