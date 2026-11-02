<?php

namespace App\Domain\Progression;

use App\Domain\Model\FeedbackAnswers;
use App\Domain\Model\Pump;
use App\Domain\Model\Soreness;
use App\Domain\Model\Workout;

/** Active rest after flat weeks (PROG-054, MESO-064). */
final class ActiveRest
{
    /** PROG-054: this many flat weeks running. */
    public const FLAT_WEEKS_RUNNING = 2;

    /** MESO-064: what an active rest is. */
    public const PLAN = [ActiveRestWeek::DeloadWeek, ActiveRestWeek::WeekOff];

    /**
     * PROG-054: most muscles trained that week reported a Low pump and Never got sore on every sheet. A muscle needs
     * at least one soreness answer that week to count as reporting none (FB-007 skips the question at times).
     *
     * @param  list<Workout>  $week
     */
    public static function isFlat(array $week): bool
    {
        /** @var array<string, list<FeedbackAnswers>> $sheets */
        $sheets = [];
        foreach ($week as $workout) {
            foreach ($workout->feedback as $muscle => $answers) {
                if ($answers->pump !== null) {
                    $sheets[$muscle][] = $answers;
                }
            }
        }

        return $sheets !== [] && Most::of($sheets, self::isFlatMuscle(...));
    }

    /**
     * PROG-054, MESO-064: the mesocycle so far had flat weeks running.
     *
     * @param  list<list<Workout>>  $weeks  oldest first
     */
    public static function isRecommended(array $weeks): bool
    {
        $running = 0;
        foreach ($weeks as $week) {
            $running = self::isFlat($week) ? $running + 1 : 0;
            if ($running >= self::FLAT_WEEKS_RUNNING) {
                return true;
            }
        }

        return false;
    }

    /** @param list<FeedbackAnswers> $sheets */
    private static function isFlatMuscle(array $sheets): bool
    {
        $soreness = array_values(array_filter(array_map(fn (FeedbackAnswers $sheet) => $sheet->soreness, $sheets)));

        return $soreness !== []
            && array_all($soreness, fn (Soreness $answer) => $answer === Soreness::NeverGotSore)
            && array_all($sheets, fn (FeedbackAnswers $sheet) => $sheet->pump === Pump::Low);
    }
}
