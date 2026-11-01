<?php

namespace App\Domain\Safety;

use App\Domain\Model\PhaseType;
use App\Domain\Model\Profile;
use App\Domain\Model\UnitConversion;
use DateTimeImmutable;
use DateTimeZone;

/** Health screening (SAFE-001) and re-checking it from Settings (SAFE-017). */
final class Screening
{
    public const MINIMUM_AGE = 18;

    public const MAXIMUM_AGE = 65;

    public const MINIMUM_HEIGHT_CM = 54 * UnitConversion::CM_PER_INCH;

    public const MAXIMUM_HEIGHT_CM = 84 * UnitConversion::CM_PER_INCH;

    public const MAXIMUM_WEIGHT_LB = 350.0;

    public const MINIMUM_BMI = 17.0;

    /** The items worked out from the profile rather than ticked (`measuredItems`). */
    public const MEASURED_KINDS = [
        ScreeningItem::AgeOutOfRange,
        ScreeningItem::HeightOutOfRange,
        ScreeningItem::WeightOverLimit,
        ScreeningItem::BmiUnderLimit,
    ];

    /** ONB-005: the nutrition steps a training-only user skipped. */
    public const NUTRITION_SETUP_STEPS = [4, 5, 6, 10, 11, 13];

    /**
     * The items worked out from the profile: age, height, weight and BMI.
     *
     * @return list<ScreeningItem>
     */
    public static function measuredItems(Profile $profile, DateTimeImmutable $today): array
    {
        return self::measuredItemsFrom($profile->birthDate, $profile->heightCm, $profile->weightKg, $today);
    }

    /**
     * The same, from the raw answers, for setup (ONB-005) before a Profile exists. Age is counted
     * in whole years between two calendar dates, each read in its own time zone, so neither a
     * time of day nor a UTC offset can move a birthday.
     *
     * @return list<ScreeningItem> in the spec's order
     */
    public static function measuredItemsFrom(
        DateTimeImmutable $birthDate, float $heightCm, float $weightKg, DateTimeImmutable $today,
    ): array {
        $utc = new DateTimeZone('UTC');
        $interval = (new DateTimeImmutable($birthDate->format('Y-m-d'), $utc))
            ->diff(new DateTimeImmutable($today->format('Y-m-d'), $utc));
        $age = $interval->invert === 1 ? -$interval->y : $interval->y;
        $heightM = $heightCm / 100;

        $items = [];
        if ($age < self::MINIMUM_AGE || $age > self::MAXIMUM_AGE) {
            $items[] = ScreeningItem::AgeOutOfRange;
        }
        if ($heightCm < self::MINIMUM_HEIGHT_CM || $heightCm > self::MAXIMUM_HEIGHT_CM) {
            $items[] = ScreeningItem::HeightOutOfRange;
        }
        if ($weightKg > UnitConversion::lbToKg(self::MAXIMUM_WEIGHT_LB)) {
            $items[] = ScreeningItem::WeightOverLimit;
        }
        // A zero height is already out of range; Swift's division gives +inf there and adds nothing.
        if ($heightM !== 0.0 && $weightKg / ($heightM * $heightM) < self::MINIMUM_BMI) {
            $items[] = ScreeningItem::BmiUnderLimit;
        }

        return $items;
    }

    /**
     * SAFE-001: the measured items plus the ones the user ticked.
     *
     * @param  list<ScreeningItem>  $answers
     */
    public static function eligibility(Profile $profile, array $answers, DateTimeImmutable $today): Eligibility
    {
        return self::eligibilityFor([...$answers, ...self::measuredItems($profile, $today)]);
    }

    /**
     * SAFE-001: from the items that apply, in any order and with repeats.
     *
     * @param  list<ScreeningItem>  $answers
     */
    public static function eligibilityFor(array $answers): Eligibility
    {
        $reasons = array_values(array_filter(
            ScreeningItem::cases(), fn (ScreeningItem $item) => in_array($item, $answers, true)));

        foreach ($reasons as $reason) {
            if ($reason->blocksAllTargets()) {
                return Eligibility::trainingOnly($reasons);
            }
        }

        return $reasons === [] ? Eligibility::allTargets() : Eligibility::fatLossBlocked($reasons);
    }

    /** SAFE-017. `$currentPhase` is the phase the user is in now. */
    public static function recheck(Eligibility $previous, Eligibility $now, PhaseType $currentPhase): RecheckOutcome
    {
        $kind = match (true) {
            ! $previous->hasNutritionTargets() && $now->hasNutritionTargets() => RecheckOutcomeKind::RunNutritionSetup,
            $previous->hasNutritionTargets() && ! $now->hasNutritionTargets() => RecheckOutcomeKind::SwitchToTrainingOnly,
            $previous->hasNutritionTargets() && ! in_array($currentPhase, $now->allowedPhases(), true) => RecheckOutcomeKind::LeaveFatLoss,
            default => RecheckOutcomeKind::Keep,
        };

        return new RecheckOutcome($kind, $now);
    }
}
