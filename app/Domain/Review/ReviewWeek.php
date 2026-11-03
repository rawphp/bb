<?php

namespace App\Domain\Review;

use App\Domain\Model\PlanWeek;

/** What the review knows about one finished week when it judges calories (REV-020 to REV-032). */
final readonly class ReviewWeek
{
    /**
     * @param  list<float>  $loggedIntakeKcal  REV-021, REV-022: checked-in and manually entered calories, one entry per logged day.
     *                                         Planned meals never checked in are not in here, whatever SET-003 says.
     * @param  float  $targetKcal  the week's average daily calorie target
     * @param  bool  $hadHigherCarbDayShift  REV-026: calories were moved onto a higher-carb day this week (NUT-021)
     * @param  bool  $isRough  SCH-063: the week was marked a rough week. Only the recommendation reads it (SCH-064):
     *                         the week still counts as an ordinary week everywhere else.
     * @param  bool  $trainingFatigueHigh  REV-040, TBL-051: the week's training fatigue was high, which REV-046 reads
     */
    public function __construct(
        public PlanWeek $week,
        public array $loggedIntakeKcal,
        public float $targetKcal,
        public bool $hadHigherCarbDayShift = false,
        public bool $isRough = false,
        public bool $trainingFatigueHigh = false,
    ) {}

    public function loggedDays(): int
    {
        return count($this->loggedIntakeKcal);
    }

    /** Average calories over the logged days; null when nothing was logged. */
    public function averageIntakeKcal(): ?float
    {
        return $this->loggedIntakeKcal === [] ? null : array_sum($this->loggedIntakeKcal) / count($this->loggedIntakeKcal);
    }

    /** A diet break, deload (which runs at maintenance, PLAN-022 and PLAN-024) or away week. */
    public function isBreakOrAway(): bool
    {
        return ReviewNextWeek::isPlanned($this->week);
    }
}
