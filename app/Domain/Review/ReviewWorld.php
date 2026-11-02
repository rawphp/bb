<?php

namespace App\Domain\Review;

use App\Domain\Model\Mesocycle;
use App\Domain\Model\Plan;
use App\Domain\Model\Profile;
use App\Domain\Retarget\OtherActivity;
use App\Domain\WeekBuilder\AwayWeek;
use App\Domain\WeekBuilder\WeekSchedule;
use Closure;

/** Everything needed to build the week a review sets, as domain values. */
final readonly class ReviewWorld
{
    /**
     * @param  Profile  $profile  weightKg is the trend weight (WT-011).
     * @param  WeekSchedule  $schedule  nutrition is null in training-only mode (SAFE-003).
     * @param  Mesocycle  $mesocycle  The mesocycle covering the week; a review that moves the deload (REV-011) hands
     *                                over the shortened one.
     * @param  int  $nextWeek  The plan week the review sets, from 0.
     * @param  Closure(): string  $newId  An id for each meal a build places. The rules never make an id.
     * @param  ?AwayWeek  $away  SCH-060: the week was marked away ahead.
     * @param  bool  $storedDietBreak  MESO-044: the week's row was marked a diet break ahead.
     * @param  bool  $storedDeload  The week's row is flagged a deload.
     * @param  bool  $dietBreakTurnedOff  PLAN-023: the user turned off the diet break of the week.
     * @param  list<int>  $pausedWeeks  SCH-060: the plan weeks that pause training; they do not count as mesocycle weeks.
     * @param  array<string, OtherActivity>  $otherActivities  SCH-038: the stored Other activities by day ("Y-m-d" in
     *                                                         the plan's time zone); their energy joins the day's.
     */
    public function __construct(
        public Plan $plan,
        public Profile $profile,
        public WeekSchedule $schedule,
        public Mesocycle $mesocycle,
        public int $mesocycleStartWeek,
        public int $nextWeek,
        public Closure $newId,
        public ?AwayWeek $away = null,
        public bool $storedDietBreak = false,
        public bool $storedDeload = false,
        public bool $dietBreakTurnedOff = false,
        public array $pausedWeeks = [],
        public array $otherActivities = [],
    ) {}
}
