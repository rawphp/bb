<?php

namespace App\Capabilities\Reads\Review;

use App\Capabilities\Review\Data\ReviewMeal;
use App\Capabilities\Review\Data\ReviewMealDay;
use App\Capabilities\Review\Data\ReviewMeals;
use App\Capabilities\Review\Data\ReviewProgress;
use App\Capabilities\Review\Data\ReviewTraining;
use App\Capabilities\Review\Data\ReviewWeighInRow;
use App\Capabilities\Review\Data\ReviewWeighIns;
use App\Capabilities\Support\Line;
use App\Capabilities\Support\Status;
use App\Capabilities\Support\Tone;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Progression\RecoveryStep;
use App\Domain\Review\JointPainFlag;
use App\Domain\Review\NextWeekKind;
use App\Domain\Review\ReviewMealItem;
use App\Domain\Review\ReviewMealsSummary;
use App\Domain\Review\ReviewProgress\Position;
use App\Domain\Review\ReviewWeighInRow as DomainRow;
use App\Support\Plan\PlanWords;
use App\Support\Review\ReviewAnalysis;
use DateTimeInterface;

/** Steps 1 to 4 of the weekly review: weigh-ins, meals, training and progress (rp-clone ReviewSteps §1 to §4). */
final class ReviewWeekPayload
{
    /** REV-003 step 1. */
    public static function weighIns(ReviewAnalysis $analysis): ReviewWeighIns
    {
        $rows = array_map(fn (DomainRow $row) => new ReviewWeighInRow(
            date: $row->day->format('Y-m-d'),
            date_label: ReviewWords::day($row->day),
            value_label: $row->weightKg === null ? '' : PlanWords::weight($row->weightKg, $analysis->weightUnit),
            is_missing: $row->isMissing,
            // A late weigh-in for the day starts at 7 in the morning.
            add_at: $row->day->modify('+7 hours')->format(DateTimeInterface::ATOM),
        ), $analysis->weighInRows);

        return new ReviewWeighIns(
            rows: $rows,
            empty_message: $rows === [] ? 'No weigh-ins were scheduled or logged this week.' : null,
            missing: new Status('Missing · Add', Tone::Warning->value),
            add_title: 'Add a weigh-in',
        );
    }

    /** REV-003 step 2: the summary's own words, and the week by day with each meal's mark (REV-006). */
    public static function meals(ReviewAnalysis $analysis): ReviewMeals
    {
        $meals = $analysis->meals;

        return new ReviewMeals(
            message: new Status($meals->message(), ($meals->isExcellent() ? Tone::Good : Tone::Neutral)->value),
            days: self::mealDays($meals),
        );
    }

    /**
     * REV-006: one row per day of the week that has planned meals, in order, each meal checked in or not.
     *
     * @return list<ReviewMealDay>
     */
    public static function mealDays(ReviewMealsSummary $meals): array
    {
        /** @var array<string, list<ReviewMealItem>> $byDay */
        $byDay = [];
        foreach ($meals->meals as $meal) {
            $byDay[$meal->day->format('Y-m-d')][] = $meal;
        }

        return array_values(array_map(fn (array $items) => new ReviewMealDay(
            date: $items[0]->day->format('Y-m-d'),
            weekday: $items[0]->day->format('D'),
            day_number: $items[0]->day->format('j'),
            meals: array_map(fn (ReviewMealItem $meal) => new ReviewMeal(
                meal_id: $meal->id,
                name: $meal->name,
                is_checked: $meal->isChecked,
                label: $meal->isChecked
                    ? "{$meal->name}, ".ReviewWords::day($meal->day).': checked in'
                    : "Check in {$meal->name}, ".ReviewWords::day($meal->day),
            ), $items),
        ), $byDay));
    }

    /** REV-003 step 3, with REV-045's recovery step. */
    public static function training(ReviewAnalysis $analysis): ReviewTraining
    {
        $training = $analysis->training;
        $titles = fn (array $muscles) => implode(', ', array_map(fn (MuscleGroup $muscle) => $muscle->title(), $muscles));
        $notes = [];
        if ($training->underperformingMuscles !== []) {
            $notes[] = new Status('Missed rep targets 2 sessions running: '.$titles($training->underperformingMuscles), Tone::Warning->value);
        }
        if ($training->musclesAtCeiling !== []) {
            $notes[] = new Status('At the volume ceiling: '.$titles($training->musclesAtCeiling), Tone::Info->value);
        }
        foreach ($training->jointPain as $flag) {
            /** @var JointPainFlag $flag */
            $notes[] = new Status("{$flag->exerciseName}: {$flag->level->label()}", Tone::Warning->value);
        }
        array_push($notes, ...self::recoveryNotes($analysis));

        return new ReviewTraining(
            lines: [
                new Line('Sessions done', (string) $training->sessionsDone, Tone::Neutral->value),
                new Line('Skipped', (string) $training->sessionsSkipped, Tone::Neutral->value),
                new Line('Missed', (string) $training->sessionsMissed, ($training->sessionsMissed > 0 ? Tone::Warning : Tone::Neutral)->value),
                new Line('Sets done', "{$training->setsDone} of {$training->setsPlanned} planned", Tone::Neutral->value),
            ],
            notes: $notes,
            next_week_label: 'Next week',
            next_week: new Status($training->nextWeek->title(), ($training->nextWeek === NextWeekKind::Deload ? Tone::Info : Tone::Neutral)->value),
        );
    }

    /** REV-003 step 4: the trend weight against the goal range, and the week's rate against the target. */
    public static function progress(ReviewAnalysis $analysis): ReviewProgress
    {
        $progress = $analysis->progress;
        if ($progress === null) {
            return new ReviewProgress([], [], 'There is not enough trend weight in this phase to show progress yet.');
        }
        $unit = $analysis->weightUnit;
        $percent = fn (float $rate) => sprintf('%+.2f%% a week', $rate);
        $pills = [new Status(
            match ($progress->position) {
                Position::Below => 'Below the goal range',
                Position::Within => 'Inside the goal range',
                Position::Above => 'Above the goal range',
            },
            ($progress->position === Position::Within ? Tone::Good : Tone::Warning)->value,
        )];
        if ($progress->rateIsOnTarget !== null) {
            $pills[] = new Status(
                $progress->rateIsOnTarget ? 'On target' : 'Off target',
                ($progress->rateIsOnTarget ? Tone::Good : Tone::Warning)->value,
            );
        }

        return new ReviewProgress(
            lines: [
                new Line('Trend weight', PlanWords::weight($progress->trendKg, $unit), Tone::Neutral->value),
                new Line('Goal range', PlanWords::weight($progress->rangeKg->lower, $unit).' to '.PlanWords::weight($progress->rangeKg->upper, $unit), Tone::Neutral->value),
                new Line('This week\'s rate', $progress->weekRatePercent === null ? 'Not enough weigh-ins' : $percent($progress->weekRatePercent), Tone::Neutral->value),
                new Line('Target rate', $percent($progress->targetRatePercent), Tone::Neutral->value),
            ],
            pills: $pills,
            empty_message: null,
        );
    }

    /**
     * REV-045: in Muscle gain, with the rate on target and the reviewed week's training fatigue high, the weight is
     * fine and the lifts are a training problem; the recovery step (PROG-052) follows.
     *
     * @return list<Status>
     */
    private static function recoveryNotes(ReviewAnalysis $analysis): array
    {
        if ($analysis->phase?->type !== PhaseType::MuscleGain || $analysis->progress?->rateIsOnTarget !== true || ! ($analysis->reviewedWeek()->trainingFatigueHigh ?? false)) {
            return [];
        }
        // PROG-052 names a step only when enough muscles underperformed.
        $steps = RecoveryStep::recommended($analysis->training->underperformingMuscles, PhaseType::MuscleGain);
        $words = array_map(fn (RecoveryStep $step) => match ($step) {
            RecoveryStep::DietBreakWeek => 'a diet break week',
            RecoveryStep::HalfSessionsForRestOfWeek => 'half sessions for the rest of the week',
            RecoveryStep::EarlyDeload => 'an early deload',
        }, $steps);
        $sentence = new Status('Your weight is on track. The lifts are a training problem, not a food one.', Tone::Info->value);
        if ($words === []) {
            return [$sentence];
        }
        $last = array_pop($words);
        $list = $words === [] ? $last : implode(', ', $words).', or '.$last;

        return [$sentence, new Status("Recovery step: {$list}.", Tone::Info->value)];
    }
}
