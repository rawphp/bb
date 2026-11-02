<?php

namespace App\Domain\Progress;

use DateTimeImmutable;
use DateTimeZone;

final class Adherence
{
    /** DAY-010: calories and protein each within this share of target. */
    public const TOLERANCE = 0.10;

    /**
     * The green dot goes to a day before today whose consumed calories and protein are both within 10% of its
     * target (DAY-010). Today, future days, a day with no target or with no consumed data get none.
     */
    public static function status(
        DateTimeImmutable $day, DateTimeImmutable $today, DateTimeImmutable $planStart,
        ?DayTotals $target, ?DayTotals $consumed, DateTimeZone $zone,
    ): DayAdherence {
        $day = Days::startOf($day, $zone);
        if ($day < Days::startOf($planStart, $zone)) {
            return DayAdherence::BeforePlan;
        }
        if ($day >= Days::startOf($today, $zone) || $target === null || $consumed === null) {
            return DayAdherence::NoDot;
        }
        $onTrack = self::within($consumed->kcal, $target->kcal) && self::within($consumed->proteinG, $target->proteinG);

        return $onTrack ? DayAdherence::OnTrack : DayAdherence::NoDot;
    }

    public static function within(float $value, float $target): bool
    {
        return abs($value - $target) <= $target * self::TOLERANCE + 1e-9;
    }
}
