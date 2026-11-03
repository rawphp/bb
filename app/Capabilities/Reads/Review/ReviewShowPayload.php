<?php

namespace App\Capabilities\Reads\Review;

use App\Capabilities\Review\Data\ReviewShowOutput;
use App\Capabilities\Review\Data\ReviewStep;
use App\Domain\WeekBuilder\BuiltWeek;
use App\Support\Review\ReviewAnalysis;
use App\Support\Review\ReviewAnswers;

/** The weekly review (REV-001 to REV-050) for the answers so far. A step the mode leaves out is null (REV-005). */
final class ReviewShowPayload
{
    private const TITLES = [
        'weigh_ins' => 'Weigh-ins',
        'meals' => 'Meals',
        'training' => 'Training',
        'progress' => 'Progress',
        'goal' => 'Goal',
        'calories' => 'Calories',
        'preview' => 'Preview',
    ];

    /** REV-003, REV-005, SCH-061: the steps of each mode, in order. */
    private const STEPS = [
        'full' => ['weigh_ins', 'meals', 'training', 'progress', 'goal', 'calories', 'preview'],
        'trainingOnly' => ['weigh_ins', 'training', 'progress'],
        'away' => ['weigh_ins', 'preview'],
    ];

    public static function build(ReviewAnalysis $analysis, ReviewAnswers $answers): ReviewShowOutput
    {
        $keys = self::STEPS[$analysis->mode->value];
        $has = fn (string $key) => in_array($key, $keys, true);
        $choices = new ReviewChoices($analysis, $answers);
        $week = ReviewPreviewPayload::week($choices);
        $reviewed = $analysis->period->reviewed;

        return new ReviewShowOutput(
            state: 'ready',
            message: null,
            week_start: $reviewed->start->format('Y-m-d'),
            week_label: $reviewed->start->format('j M').' to '.($reviewed->end() ?? $reviewed->start)->format('j M'),
            steps: array_map(fn (string $key) => new ReviewStep($key, self::TITLES[$key]), $keys),
            weigh_ins: $has('weigh_ins') ? ReviewWeekPayload::weighIns($analysis) : null,
            meals: $has('meals') ? ReviewWeekPayload::meals($analysis) : null,
            training: $has('training') ? ReviewWeekPayload::training($analysis) : null,
            progress: $has('progress') ? ReviewWeekPayload::progress($analysis) : null,
            goal: $has('goal') ? ReviewGoalPayload::build($analysis) : null,
            calories: $has('calories') ? ReviewCaloriesPayload::build($analysis, $answers, $choices) : null,
            preview: $has('preview') ? ReviewPreviewPayload::section($choices, $week) : null,
            can_save: $week instanceof BuiltWeek,
            save_title: 'Save',
        );
    }

    /** REV-001: nothing to review; the message says why. */
    public static function unavailable(string $message): ReviewShowOutput
    {
        return new ReviewShowOutput(
            state: 'unavailable', message: $message, week_start: null, week_label: '', steps: [],
            weigh_ins: null, meals: null, training: null, progress: null, goal: null, calories: null, preview: null,
            can_save: false, save_title: 'Save',
        );
    }
}
