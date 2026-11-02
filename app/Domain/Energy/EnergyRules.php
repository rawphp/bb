<?php

namespace App\Domain\Energy;

use App\Domain\Defaults\TBL030ActivityFactor;
use App\Domain\Defaults\TBL031SessionIntensity;
use App\Domain\Macros\MacroRules;
use App\Domain\Model\DayType;
use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Profile;
use App\Domain\Model\Sex;
use App\Domain\Model\StepPlanDay;
use DateTimeImmutable;
use DateTimeZone;

/** Daily energy expenditure and calorie target (NUT-001 to NUT-007), session length and cardio load. Pure functions. */
final class EnergyRules
{
    /** SCH-062: more than this many hard Other activities in a plan week is too much cardio in Fat loss. */
    public const CARDIO_HARD_SESSIONS_PER_WEEK = 4;

    /**
     * NUT-001: age in whole years on $on. The birth date is a calendar date, read in its own time zone, so
     * no UTC offset moves the birthday; $on is read as the calendar day it is in $timeZone. Same counting as
     * Screening's age (SAFE-013).
     */
    public static function age(DateTimeImmutable $birthDate, DateTimeImmutable $on, DateTimeZone $timeZone): int
    {
        $today = $on->setTimezone($timeZone);
        $age = (int) $today->format('Y') - (int) $birthDate->format('Y');

        return $today->format('md') < $birthDate->format('md') ? $age - 1 : $age;
    }

    /** NUT-001: basal metabolic rate, Mifflin-St Jeor, in kcal. */
    public static function bmr(Profile $profile, float $weightKg, DateTimeImmutable $on, DateTimeZone $timeZone): float
    {
        $years = self::age($profile->birthDate, $on, $timeZone);
        $base = 10 * $weightKg + 6.25 * $profile->heightCm - 5 * $years;

        return match ($profile->sex) {
            Sex::Male => $base + 5,
            Sex::Female => $base - 161,
        };
    }

    /** TBL-030: activity factor for a daily step count. */
    public static function activityFactor(int $steps): float
    {
        $rows = TBL030ActivityFactor::rows();
        $factor = $rows[0]->factor;
        foreach ($rows as $row) {
            if ($steps >= $row->minSteps) {
                $factor = $row->factor;
            }
        }

        return $factor;
    }

    /** NUT-002, TBL-030: the step count a plan day is looked up by, the midpoint in whole steps. */
    public static function stepMidpoint(StepPlanDay $day): int
    {
        return intdiv($day->min + $day->max, 2);
    }

    /** NUT-002, TBL-030: activity factor for a planned step range. */
    public static function activityFactorForDay(StepPlanDay $day): float
    {
        return self::activityFactor(self::stepMidpoint($day));
    }

    /** NUT-002: BMR x activity factor, plus the day's workout energy. */
    public static function dailyExpenditure(float $bmr, float $activityFactor, float $workoutKcal): float
    {
        return $bmr * $activityFactor + $workoutKcal;
    }

    /** NUT-006: the measured estimate (REV-021) replaces the formula once there is one. */
    public static function expenditure(float $formula, ?float $measured): float
    {
        return $measured ?? $formula;
    }

    /** NUT-007: expenditure plus the phase's daily energy change at $weightKg (SAFE-005 clamp applies). */
    public static function dailyTarget(float $expenditure, Phase $phase, float $weightKg): float
    {
        return $expenditure + MacroRules::dailyKcal(MacroRules::targetRatePercent($phase), $weightKg);
    }

    /**
     * SCH-046: planned session length, working sets x 2.5 min + 10, to the nearest 5 (ties up).
     * With at least 3 past actual lengths of the same mesocycle day (oldest first), the mean of the last 3.
     *
     * @param  list<float|int>  $pastActualMinutes
     */
    public static function sessionMinutes(int $workingSets, array $pastActualMinutes = []): int
    {
        $raw = count($pastActualMinutes) >= 3
            ? array_sum(array_slice($pastActualMinutes, -3)) / 3
            : $workingSets * 2.5 + 10;

        return (int) round($raw / 5) * 5;
    }

    /** NUT-004, TBL-031: a mesocycle session's intensity. Never deload. */
    public static function sessionIntensity(int $workingSets, float $minutes, bool $isDeloadWeek): DayType
    {
        if ($isDeloadWeek) {
            return TBL031SessionIntensity::DELOAD_WEEK_INTENSITY;
        }
        if ($workingSets >= TBL031SessionIntensity::HARD_FROM_SETS
            || $minutes > TBL031SessionIntensity::HARD_OVER_ESTIMATED_MINUTES) {
            return TBL031SessionIntensity::HARD_INTENSITY;
        }
        if (TBL031SessionIntensity::moderateSets()->contains($workingSets)) {
            return TBL031SessionIntensity::MODERATE_INTENSITY;
        }

        return TBL031SessionIntensity::LIGHT_INTENSITY;
    }

    /** NUT-003: energy factor per hour per kg. Rest and the unused deload case count as no workout. */
    public static function workoutFactor(DayType $intensity): float
    {
        return match ($intensity) {
            DayType::Light => 3,
            DayType::Moderate => 5,
            DayType::Hard => 6,
            DayType::Rest, DayType::Deload => 0,
        };
    }

    /** NUT-003: hours x bodyweight x intensity factor. */
    public static function workoutEnergy(float $minutes, float $weightKg, DayType $intensity): float
    {
        return $minutes / 60 * $weightKg * self::workoutFactor($intensity);
    }

    /**
     * SCH-038: a day's workout energy (summed) and day type (the highest intensity, rest with none).
     *
     * @param  list<EnergyWorkout>  $workouts
     */
    public static function dayWorkout(array $workouts, float $weightKg): DayWorkout
    {
        $kcal = 0.0;
        $dayType = DayType::Rest;
        foreach ($workouts as $workout) {
            $kcal += self::workoutEnergy($workout->minutes, $weightKg, $workout->intensity);
            if (self::rank($workout->intensity) > self::rank($dayType)) {
                $dayType = $workout->intensity;
            }
        }

        return new DayWorkout($kcal, $dayType);
    }

    /**
     * SCH-062, PLAN-009: the warning (never a block) when a Fat loss or Mini-cut plan week holds more than
     * CARDIO_HARD_SESSIONS_PER_WEEK hard Other activities; null otherwise.
     */
    public static function cardioWarning(PhaseType $phase, int $hardOtherActivitiesInWeek): ?string
    {
        if (! $phase->isFatLoss() || $hardOtherActivitiesInWeek <= self::CARDIO_HARD_SESSIONS_PER_WEEK) {
            return null;
        }

        return "Too much cardio hurts lifting recovery. That makes {$hardOtherActivitiesInWeek} hard sessions this week; "
            .'more than '.self::CARDIO_HARD_SESSIONS_PER_WEEK.' is a lot during fat loss.';
    }

    /** SCH-038: rest < light < moderate < hard. */
    private static function rank(DayType $type): int
    {
        return match ($type) {
            DayType::Rest, DayType::Deload => 0,
            DayType::Light => 1,
            DayType::Moderate => 2,
            DayType::Hard => 3,
        };
    }
}
