<?php

namespace App\Domain\Review;

use App\Domain\Model\FeedbackAnswers;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PlanWeek;

/** What the review knows about one finished week when it judges fatigue (REV-040). */
final readonly class FatigueWeek
{
    /**
     * @param  list<MuscleGroup>  $underperformingMuscles  PROG-050: muscles flagged underperforming in the week (each once)
     * @param  list<FeedbackAnswers>  $feedback  the week's feedback sheets
     * @param  int  $loggedDays  days with food checked in or entered
     */
    public function __construct(
        public PlanWeek $week,
        public array $underperformingMuscles = [],
        public array $feedback = [],
        public int $skippedSessions = 0,
        public int $loggedDays = 0,
    ) {}

    /** A diet break, or a deload (which runs at maintenance, PLAN-022 and PLAN-024). */
    public function isBreak(): bool
    {
        return $this->week->isDietBreak || $this->week->isDeload;
    }
}
