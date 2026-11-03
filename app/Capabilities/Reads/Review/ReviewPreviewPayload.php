<?php

namespace App\Capabilities\Reads\Review;

use App\Capabilities\Review\Data\ReviewMoveInput;
use App\Capabilities\Review\Data\ReviewMoveLimits;
use App\Capabilities\Review\Data\ReviewPreview;
use App\Capabilities\Review\Data\ReviewPreviewDay;
use App\Domain\Review\NextWeekCalories;
use App\Domain\Review\ReviewMode;
use App\Domain\Review\ReviewNextWeek;
use App\Domain\WeekBuilder\BuiltWeek;
use App\Domain\WeekBuilder\CalorieMove;
use App\Domain\WeekBuilder\WeekBuildRefusal;
use App\Domain\WeekBuilder\WeekDay;
use App\Support\Review\ReviewAnalysis;
use App\Support\Review\ReviewAnswers;
use App\Support\Review\ReviewMessages;

/** Step 7 of the weekly review: next week's days with the answered moves applied (rp-clone WeeklyReviewModel+Preview). */
final class ReviewPreviewPayload
{
    public static function build(ReviewAnalysis $analysis, ReviewAnswers $answers, ?ReviewChoices $choices = null): ReviewPreview
    {
        $choices ??= new ReviewChoices($analysis, $answers);

        return self::section($choices, self::week($choices));
    }

    /**
     * The week Save would write for the answers: built; the refusal when the rules refuse it (SAFE-008 to SAFE-012,
     * NUT-022, NUT-024); null when the selected option stands for no calories yet.
     */
    public static function week(ReviewChoices $choices): BuiltWeek|WeekBuildRefusal|null
    {
        $analysis = $choices->analysis;
        $world = $analysis->world;
        if ($world === null) {
            return null;
        }
        $repeatKcal = $analysis->repeatKcal;
        switch ($analysis->mode) {
            case ReviewMode::TrainingOnly:
                $calories = NextWeekCalories::planned();
                $repeatKcal = null;
                break;
            case ReviewMode::Away:
                $calories = $choices->repeatCalories();
                break;
            case ReviewMode::Full:
                $selected = $choices->selected();
                $choice = $selected === null ? null : $choices->choice($selected);
                if ($choice === null) {
                    return null;
                }
                $calories = $selected === 'repeat_this_week' ? $choices->repeatCalories() : $choice['calories'];
                $world = $choice['world'];
                break;
        }

        try {
            return ReviewNextWeek::forSave(
                $world, $calories, $repeatKcal, $choices->answers->moves, $analysis->today, $analysis->plannedNextWeek, repeatWorld: $analysis->world,
            );
        } catch (WeekBuildRefusal $refusal) {
            return $refusal;
        }
    }

    public static function section(ReviewChoices $choices, BuiltWeek|WeekBuildRefusal|null $week): ReviewPreview
    {
        $answers = $choices->answers;

        return new ReviewPreview(
            days: $week instanceof BuiltWeek ? self::days($choices->analysis, $week) : [],
            moves: array_map(fn (CalorieMove $move) => new ReviewMoveInput($move->from, $move->to, (int) round($move->kcal)), $answers->moves),
            hint: 'Tap a day to move calories between days. The week\'s total stays the same.',
            error: match (true) {
                $week instanceof WeekBuildRefusal => ReviewMessages::refusal($week),
                $week instanceof BuiltWeek => $answers->refusal,
                default => null,
            },
            move: new ReviewMoveLimits('Move calories', 25, 500, 25),
        );
    }

    /** @return list<ReviewPreviewDay> */
    private static function days(ReviewAnalysis $analysis, BuiltWeek $week): array
    {
        // NUT-024: a day already past is locked, read in the plan's time zone as ReviewNextWeek::forSave does.
        $zone = $analysis->world?->plan->start->getTimezone() ?? $analysis->today->getTimezone();

        return array_map(function (WeekDay $day, int $index) use ($analysis, $zone) {
            $kcal = $day->target?->kcal;
            $locked = ReviewNextWeek::isPast($day->day->date, $analysis->today, $zone);

            return new ReviewPreviewDay(
                index: $index,
                date: $day->day->date->format('Y-m-d'),
                date_label: ReviewWords::day($day->day->date),
                detail: implode(' · ', array_filter([ucfirst($day->day->type->value), $day->workouts[0]->session->name ?? null])),
                kcal_label: $kcal === null ? null : ReviewWords::kcal($kcal),
                can_move: $kcal !== null && ! $locked,
                is_locked: $locked,
            );
        }, $week->days, array_keys($week->days));
    }
}
