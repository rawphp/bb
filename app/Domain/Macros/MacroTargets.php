<?php

namespace App\Domain\Macros;

use App\Domain\Defaults\TBL001BodyFatBuckets;
use App\Domain\Defaults\TBL032CarbSanityBounds;
use App\Domain\Model\DayType;
use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use App\Domain\Model\PlanWeek;
use App\Domain\Model\Profile;
use App\Domain\Model\UnitConversion;

/**
 * Turns a week of day energies and a phase into daily calorie, protein, fat and carb targets
 * (NUT-010 to NUT-018, NUT-020 to NUT-023) within the safety rails (SAFE-005, 008, 009, 011, 012).
 */
final class MacroTargets
{
    /**
     * @param  Profile  $profile  weightKg should be the trend weight
     * @param  list<PlannedDay>  $days
     * @param  float|null  $bodyFatPercent  overrides the TBL-001 bucket value
     * @param  PhaseType|null  $previousPhaseType  the phase before this one, for the NUT-011 protein boost
     * @param  int  $weekOfPhase  1-based
     *
     * @throws TargetRefusal
     */
    public static function week(
        Profile $profile,
        Phase $phase,
        PlanWeek $week,
        array $days,
        int $mealsPerDay,
        ?float $bodyFatPercent = null,
        ?PhaseType $previousPhaseType = null,
        int $weekOfPhase = 1,
    ): WeekTargets {
        $weight = $profile->weightKg;
        $bodyFat = $bodyFatPercent ?? self::bucketBodyFatPercent($profile);
        $leanMass = $weight * (1 - $bodyFat / 100);
        if ($days === []) {
            return new WeekTargets([], $leanMass, $mealsPerDay);
        }

        // NUT-007 daily change; SAFE-005 clamp; NUT-023, PLAN-022, PLAN-024 zero it for deload and diet-break weeks.
        $rate = ($week->isDeload || $week->isDietBreak) ? 0 : MacroRules::targetRatePercent($phase);
        $dailyChange = MacroRules::dailyKcal($rate, $weight);

        $bmi = self::bmi($profile);
        if ($dailyChange < 0 && $bmi !== null && $bmi <= MacroRules::BMI_CUT_FLOOR) {
            throw TargetRefusal::bmiAtOrBelowFloor($bmi); // SAFE-011
        }

        // NUT-014: each day's calories are its own expenditure plus the phase change.
        $dayKcal = array_map(fn (PlannedDay $day) => $day->expenditureKcal + $dailyChange, $days);
        $average = array_sum($dayKcal) / count($dayKcal);

        // NUT-010, SAFE-008: protein is the same every day and never depends on the deficit.
        $leanAdjusted = $bodyFat <= MacroRules::leanAdjustThresholdPercent($profile->sex)
            ? $weight : $leanMass * MacroRules::LEAN_ADJUST_FACTOR;
        $protein = MacroRules::PROTEIN_G_PER_KG * $leanAdjusted;
        if ($phase->type === PhaseType::MuscleGain && $previousPhaseType?->isFatLoss() === true
            && $weekOfPhase <= MacroRules::PROTEIN_BOOST_WEEKS) {
            $protein = min(MacroRules::PROTEIN_BOOST_FACTOR * $protein, MacroRules::PROTEIN_BOOST_CAP_G_PER_KG * $weight); // NUT-011
        }

        // NUT-012, SAFE-009, SAFE-010: same fat grams every day.
        $minimumMealG = MacroRules::MEAL_MINIMUM_G * $mealsPerDay;
        $fatFloor = max(
            MacroRules::FAT_FLOOR_SHARE_OF_CALORIES * max($dayKcal) / MacroRules::KCAL_PER_GRAM_FAT,
            MacroRules::FAT_FLOOR_G_PER_KG_LEAN * $leanMass,
            $minimumMealG,
        );
        $fat = max(MacroRules::FAT_SHARE_OF_CALORIES * $average / MacroRules::KCAL_PER_GRAM_FAT, $fatFloor);

        // NUT-013, NUT-014: carbs take the rest; SAFE-012: refuse instead of going below the carb minimum.
        $targets = [];
        foreach ($days as $index => $planned) {
            $carbs = ($dayKcal[$index] - MacroRules::KCAL_PER_GRAM_PROTEIN * $protein
                - MacroRules::KCAL_PER_GRAM_FAT * $fat) / MacroRules::KCAL_PER_GRAM_CARBS;
            if ($carbs < $minimumMealG) {
                throw TargetRefusal::breaksFloor($index);
            }
            $targets[] = new DayTarget(
                $planned->type, $planned->isCompleted, proteinG: $protein, fatG: $fat, carbsG: $carbs,
                carbBoundsWarning: self::carbWarning($planned->type, $carbs, $leanMass),
            );
        }

        return new WeekTargets($targets, $leanMass, $mealsPerDay);
    }

    /** Null when the profile has no height. */
    public static function bmi(Profile $profile): ?float
    {
        if ($profile->heightCm <= 0) {
            return null;
        }
        $meters = $profile->heightCm / 100;

        return $profile->weightKg / ($meters * $meters);
    }

    /** TBL-032: whether a day's carbs sit outside the sanity bounds for its type. */
    public static function carbWarning(DayType $type, float $carbsG, float $leanMassKg): ?CarbBoundsWarning
    {
        $bounds = TBL032CarbSanityBounds::gramsPerLbLeanMass($type);
        if ($bounds === null) {
            return null;
        }
        $perLb = $carbsG / UnitConversion::kgToLb($leanMassKg);
        if ($perLb < $bounds->lower) {
            return CarbBoundsWarning::Below;
        }
        if ($perLb > $bounds->upper) {
            return CarbBoundsWarning::Above;
        }

        return null;
    }

    /**
     * The lean mass week() works from when no body fat was measured: weight less the bucket's body fat.
     * It takes no measurement, so it differs from week(bodyFatPercent: ...)'s lean mass when one was given.
     */
    public static function leanMassKg(Profile $profile): float
    {
        return $profile->weightKg * (1 - self::bucketBodyFatPercent($profile) / 100);
    }

    private static function bucketBodyFatPercent(Profile $profile): float
    {
        return TBL001BodyFatBuckets::bodyFatPercent($profile->sex, $profile->bodyFatBucket);
    }
}
