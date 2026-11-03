<?php

namespace App\Domain\Progression;

use App\Domain\Model\ExperienceLevel;

/** What SetPlan needs about one exercise's sets. Weights are in the user's unit. */
final readonly class SetPlanInputs
{
    /**
     * @param  list<SetLoad>  $lastWeek  last week's logged sets of this exercise on this day
     * @param  ?SetLoad  $carried  the first logged set of this exercise in week 1 of the previous mesocycle
     * @param  ?int  $rir  null in a deload week
     * @param  bool  $lastWeekWasRough  SCH-063: last week was a rough week, so its sets are repeated, not raised
     */
    public function __construct(
        public int $sets,
        public array $lastWeek,
        public ?SetLoad $carried,
        public ?int $rir,
        public ?DeloadHalf $deloadHalf,
        public bool $isFatLoss,
        public ExperienceLevel $experience,
        public LoadRules $rules,
        public bool $lastWeekWasRough = false,
    ) {}
}
