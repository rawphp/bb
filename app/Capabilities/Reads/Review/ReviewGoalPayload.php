<?php

namespace App\Capabilities\Reads\Review;

use App\Capabilities\Review\Data\ReviewChoice;
use App\Capabilities\Review\Data\ReviewGoal;
use App\Capabilities\Support\Status;
use App\Capabilities\Support\Tone;
use App\Domain\Model\PlanWeek;
use App\Domain\Phases\FinalReviewChoice;
use App\Domain\Phases\FinalReviewOption;
use App\Domain\Phases\NextPhase;
use App\Support\Review\ReviewAnalysis;
use App\Support\Review\ReviewMessages;

/** Step 5 of the weekly review: keep the goal, choose a new one or end the plan (rp-clone goalOptions, ReviewGoalStep). */
final class ReviewGoalPayload
{
    public static function build(ReviewAnalysis $analysis): ReviewGoal
    {
        $notes = [];
        // REV-043: fatigue that stayed high after a diet break and a deload.
        if ($analysis->fatigue !== null && $analysis->fatigue->dropFatigue === null) {
            $notes[] = new Status(ReviewMessages::fatigue($analysis->fatigue, $analysis->weeksSinceBreak), Tone::Warning->value);
        }

        return new ReviewGoal(
            notes: $notes,
            options: array_map(fn (FinalReviewOption $option) => self::choice($option), self::options($analysis)),
        );
    }

    /**
     * PH-041: the final review offers Keep goal (extend, up to the TBL-010 maximum), Choose new goal and End plan.
     * Before the phase's last week, keeping the goal is only "carry on".
     *
     * @return list<FinalReviewOption>
     */
    private static function options(ReviewAnalysis $analysis): array
    {
        $carryOn = [FinalReviewOption::keepGoal(null), FinalReviewOption::chooseNewGoal(), FinalReviewOption::endPlan()];
        $phase = $analysis->phase;
        if ($phase === null || $phase->end === null) {
            return $carryOn;
        }
        $nextStart = $analysis->reviewed->start->setTime(0, 0)->modify('+'.PlanWeek::LENGTH_IN_DAYS.' days');
        $end = $phase->end->setTime(0, 0);
        if ($end > $nextStart) {
            return $carryOn;
        }
        $days = (int) $phase->start->setTime(0, 0)->diff($end)->format('%r%a');

        return NextPhase::finalReviewOptions($phase->type, intdiv($days, PlanWeek::LENGTH_IN_DAYS));
    }

    private static function choice(FinalReviewOption $option): ReviewChoice
    {
        $keep = $option->choice === FinalReviewChoice::KeepGoal;

        return new ReviewChoice(
            id: match ($option->choice) {
                FinalReviewChoice::KeepGoal => 'keep_goal',
                FinalReviewChoice::ChooseNewGoal => 'choose_new_goal',
                FinalReviewChoice::EndPlan => 'end_plan',
            },
            title: match ($option->choice) {
                FinalReviewChoice::KeepGoal => 'Keep current goal',
                FinalReviewChoice::ChooseNewGoal => 'Choose a new goal',
                FinalReviewChoice::EndPlan => 'End the plan',
            },
            detail: $keep && $option->maxExtraWeeks !== null ? "Extend the phase by up to {$option->maxExtraWeeks} weeks" : '',
            is_recommended: $keep,
            is_selected: $keep,
            is_enabled: true,
        );
    }
}
