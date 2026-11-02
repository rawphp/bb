<?php

namespace Tests\Unit\Domain\Plan;

use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Plan;
use App\Domain\Plan\DatedMesocycle;
use App\Domain\Plan\PhaseRefusal;
use App\Domain\Plan\PhaseRefused;
use App\Domain\Plan\PlanWeekGrid;
use Closure;
use DateTimeImmutable;
use DateTimeZone;

/** Shared fixtures (rp-clone's PlanTestSupport): UTC, and a plan that starts on Monday 2026-01-05 (week 0). */
final class PlanFixtures
{
    public const MONDAY = 2;

    public const TUESDAY = 3;

    public const WEDNESDAY = 4;

    public const THURSDAY = 5;

    public const FRIDAY = 6;

    public static function date(int $year, int $month, int $day, int $hour = 0, int $minute = 0, string $zone = 'UTC'): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone($zone)))
            ->setDate($year, $month, $day)
            ->setTime($hour, $minute);
    }

    /** Monday 2026-01-05, review day Monday. */
    public static function grid(): PlanWeekGrid
    {
        return new PlanWeekGrid(self::date(2026, 1, 5), self::MONDAY);
    }

    public static function phase(PhaseType $type, int $from, ?int $to, float $rate = 0, ?PlanWeekGrid $grid = null, string $id = 'phase'): Phase
    {
        $grid ??= self::grid();

        return new Phase(
            id: $id,
            type: $type,
            start: $grid->start($from),
            end: $to === null ? null : $grid->start($to),
            targetRatePercentPerWeek: $rate,
        );
    }

    /** @param  list<Phase>  $phases */
    public static function plan(array $phases, ?PlanWeekGrid $grid = null): Plan
    {
        $grid ??= self::grid();

        return new Plan(id: 'plan', start: $grid->start(0), reviewWeekday: $grid->reviewWeekday(), phases: $phases);
    }

    public static function span(int $fromWeek, int $weeks): DatedMesocycle
    {
        return new DatedMesocycle(self::grid()->start($fromWeek), $weeks);
    }

    /** The refusal a call threw, or null when it threw none. */
    public static function refusal(Closure $call): ?PhaseRefusal
    {
        try {
            $call();
        } catch (PhaseRefused $refused) {
            return $refused->refusal;
        }

        return null;
    }
}
