<?php

namespace Tests\Unit\Domain\Progress;

use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use App\Domain\Model\WeighIn;
use App\Domain\Progress\ProgressMesocycle;
use App\Domain\Weight\TrendWeight;
use DateTimeImmutable;
use DateTimeZone;

/** Shared builders for the Progress rule tests (rp-clone's ProgressTestSupport): UTC, and days written as "2026-10-10". */
final class ProgressFixtures
{
    public static function zone(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }

    /** The start of a day, plus $days. */
    public static function day(string $text, int $plus = 0): DateTimeImmutable
    {
        return (new DateTimeImmutable($text, self::zone()))->modify("+{$plus} days");
    }

    /** A weigh-in at 07:00 on a day, plus $days. */
    public static function weighIn(string $text, float $kg, int $plus = 0): WeighIn
    {
        return new WeighIn("{$text}+{$plus}", self::day($text, $plus)->setTime(7, 0), $kg);
    }

    /**
     * The same weight on $count days in a row from $start: its trend is that weight too.
     *
     * @return list<WeighIn>
     */
    public static function steady(string $start, int $days, float $kg): array
    {
        return array_map(fn (int $i) => self::weighIn($start, $kg, $i), $days > 0 ? range(0, $days - 1) : []);
    }

    /** @param  list<WeighIn>  $weighIns */
    public static function trend(array $weighIns): TrendWeight
    {
        return new TrendWeight($weighIns, self::zone());
    }

    public static function phase(string $id, PhaseType $type, string $from, ?string $to, float $rate): Phase
    {
        return new Phase(
            id: $id, type: $type, start: self::day($from), end: $to === null ? null : self::day($to),
            targetRatePercentPerWeek: $rate,
        );
    }

    public static function mesocycle(string $id, ?string $phase, string $from, int $weeks): ProgressMesocycle
    {
        return new ProgressMesocycle(id: $id, phaseId: $phase, name: "Meso {$id}", start: self::day($from), weeks: $weeks);
    }
}
