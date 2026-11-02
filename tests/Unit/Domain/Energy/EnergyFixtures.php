<?php

namespace Tests\Unit\Domain\Energy;

use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\HeightUnit;
use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Profile;
use App\Domain\Model\Sex;
use App\Domain\Model\WeightUnit;
use DateTimeImmutable;
use DateTimeZone;

/** Shared inputs for the energy tests, the same as rp-clone's EnergyRulesTests. */
final class EnergyFixtures
{
    public const ACC = 1e-9;

    public static function utc(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }

    public static function date(int $year, int $month, int $day): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', self::utc()))->setDate($year, $month, $day)->setTime(0, 0);
    }

    /** 2026-10-10 00:00 UTC. */
    public static function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('@1791590400');
    }

    public static function profile(
        Sex $sex = Sex::Male,
        ?DateTimeImmutable $birthDate = null,
        float $heightCm = 185,
        float $weightKg = 125,
    ): Profile {
        return new Profile(
            name: 'Test', sex: $sex, birthDate: $birthDate ?? self::date(1980, 1, 1), heightCm: $heightCm,
            weightKg: $weightKg, weightUnit: WeightUnit::Kg, heightUnit: HeightUnit::Cm,
            bodyFatBucket: BodyFatBucket::High, usesGLP1: false, experience: ExperienceLevel::Intermediate,
        );
    }

    public static function phase(PhaseType $type, float $ratePercent): Phase
    {
        return new Phase(id: 'phase', type: $type, start: self::today(), end: null, targetRatePercentPerWeek: $ratePercent);
    }
}
