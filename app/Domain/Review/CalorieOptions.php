<?php

namespace App\Domain\Review;

use App\Domain\Model\PhaseType;
use App\Domain\Model\PlanWeek;

/** What the calorie step offers for a week (REV-010, REV-012). */
final readonly class CalorieOptions
{
    public const DIET_BREAK_MESSAGE = 'Next week is a diet break at maintenance calories';

    /**
     * @param  list<CalorieOptionKind>  $kinds
     * @param  bool  $nextIsDietBreak  REV-012: next week is already a diet break, or a deload during Fat loss (PLAN-022).
     */
    public function __construct(
        public array $kinds,
        public bool $nextIsDietBreak,
    ) {}

    /**
     * @param  PlanWeek  $reviewed  the week that ended
     * @param  PlanWeek  $next  the week ahead as planned: its flags include a deload the mesocycle puts there and a
     *                          diet break marked ahead (MESO-044)
     */
    public static function options(PhaseType $phase, PlanWeek $reviewed, PlanWeek $next): self
    {
        $nextIsDietBreak = self::isDietBreak($next, $phase);
        // REV-012: only Repeat and Choose my own beside the planned break.
        if ($nextIsDietBreak) {
            return new self([CalorieOptionKind::RepeatThisWeek, CalorieOptionKind::ChooseOwn], true);
        }
        $kinds = [CalorieOptionKind::Recommendation, CalorieOptionKind::RepeatThisWeek, CalorieOptionKind::ChooseOwn];
        // REV-010: Fat loss only, and not when the week that ended was already a break.
        if ($phase->isFatLoss() && ! self::isDietBreak($reviewed, $phase)) {
            $kinds[] = CalorieOptionKind::DropFatigue;
        }

        return new self($kinds, false);
    }

    /** PLAN-022: a Fat loss deload runs at maintenance, so it is a diet break however the week is marked. */
    private static function isDietBreak(PlanWeek $week, PhaseType $phase): bool
    {
        return $week->isDietBreak || ($week->isDeload && $phase->isFatLoss());
    }
}
