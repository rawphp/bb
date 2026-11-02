<?php

namespace Tests\Unit\Domain\Macros;

use App\Domain\Macros\MacroTargets;
use App\Domain\Macros\MoveRefusal;
use App\Domain\Macros\PlannedDay;
use App\Domain\Macros\TargetRefusal;
use App\Domain\Macros\WeekTargets;
use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\DayType;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\HeightUnit;
use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use App\Domain\Model\PlanWeek;
use App\Domain\Model\Profile;
use App\Domain\Model\Sex;
use App\Domain\Model\WeightUnit;
use DateTimeImmutable;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Shared inputs for the macro tests. Default athlete: male, 90 kg, 180 cm, bucket low (18.5% body fat,
 * lean mass 73.35 kg, protein 198 g).
 */
final class MacroFixtures
{
    public const ACC = 0.001;

    public static function epoch(): DateTimeImmutable
    {
        return new DateTimeImmutable('@1800000000');
    }

    public static function profile(
        Sex $sex = Sex::Male,
        float $heightCm = 180,
        float $weightKg = 90,
        BodyFatBucket $bucket = BodyFatBucket::Low,
    ): Profile {
        return new Profile(
            name: 'Test', sex: $sex, birthDate: self::epoch(), heightCm: $heightCm, weightKg: $weightKg,
            weightUnit: WeightUnit::Kg, heightUnit: HeightUnit::Cm, bodyFatBucket: $bucket, usesGLP1: false,
            experience: ExperienceLevel::Intermediate,
        );
    }

    public static function phase(PhaseType $type = PhaseType::Maintenance, float $ratePercent = 0): Phase
    {
        return new Phase(
            id: 'phase', type: $type, start: self::epoch(), end: self::epoch()->modify('+56 days'),
            targetRatePercentPerWeek: $ratePercent,
        );
    }

    public static function week(bool $deload = false, bool $dietBreak = false): PlanWeek
    {
        return new PlanWeek(start: self::epoch(), isDeload: $deload, isDietBreak: $dietBreak);
    }

    /**
     * Expenditure 2600 / 3100 / 2600 / 3100 / 2900 / 2600 / 2700: sum 19600, average 2800.
     *
     * @return list<PlannedDay>
     */
    public static function mixedDays(): array
    {
        return [
            new PlannedDay(2600, DayType::Rest),
            new PlannedDay(3100, DayType::Hard),
            new PlannedDay(2600, DayType::Rest),
            new PlannedDay(3100, DayType::Hard),
            new PlannedDay(2900, DayType::Moderate),
            new PlannedDay(2600, DayType::Rest),
            new PlannedDay(2700, DayType::Light),
        ];
    }

    /** @return list<PlannedDay> */
    public static function uniformDays(float $kcal, DayType $type, int $count = 7): array
    {
        return array_fill(0, $count, new PlannedDay($kcal, $type));
    }

    /** @param  list<PlannedDay>|null  $days */
    public static function targets(
        ?Profile $profile = null,
        ?Phase $phase = null,
        ?PlanWeek $week = null,
        ?array $days = null,
        int $meals = 4,
        ?float $bodyFatPercent = null,
        ?PhaseType $previous = null,
        int $weekOfPhase = 1,
    ): WeekTargets {
        return MacroTargets::week(
            profile: $profile ?? self::profile(), phase: $phase ?? self::phase(), week: $week ?? self::week(),
            days: $days ?? self::mixedDays(), mealsPerDay: $meals, bodyFatPercent: $bodyFatPercent,
            previousPhaseType: $previous, weekOfPhase: $weekOfPhase,
        );
    }

    /** Maintenance week on mixedDays: protein 198, fat 700/9, carbs 277 / 402 / 352 / 302. */
    public static function base(): WeekTargets
    {
        return self::targets();
    }

    /** Lowest bucket, seven rest days at 1800 kcal: protein 198 g, fat 52.272 g (lean arm), carbs 134.388 g. */
    public static function lowestRestWeek(): WeekTargets
    {
        return self::targets(profile: self::profile(bucket: BodyFatBucket::Lowest), days: self::uniformDays(1800, DayType::Rest));
    }

    /** Cap for training-day carbs on the default athlete: 3 g per lb of 73.35 kg lean mass. */
    public static function carbCapG(): float
    {
        return 3.0 * 73.35 / 0.45359237;
    }

    /** The refusal a rule throws; fails the test when it returns instead. */
    public static function refusal(callable $rule): TargetRefusal|MoveRefusal
    {
        try {
            $rule();
        } catch (TargetRefusal|MoveRefusal $refusal) {
            return $refusal;
        }

        throw new AssertionFailedError('Expected a refusal, got a result.');
    }
}
