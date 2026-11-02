<?php

namespace App\Domain\Progression;

use DateTimeImmutable;

/**
 * Which Feedback sheets a session asks and when each falls due (FB-001, FB-002, FB-007, FB-008, rp-clone
 * WorkoutModel+Feedback), and when one is fully answered (WO-040). workout_show sends them ahead so the session can
 * be answered offline (OFF-020).
 */
final class FeedbackSheets
{
    /** FB-007: soreness is skipped when the muscle last trained longer ago than this. */
    public const SORENESS_WINDOW_DAYS = 14;

    /**
     * FB-001: one sheet per muscle that has an exercise with sets, in the order of each muscle's last such exercise.
     * FB-008: none in a deload week; none for a skipped session.
     *
     * @param  list<FeedbackBlock>  $blocks  the session's exercises in order
     * @return list<FeedbackSheet>
     */
    public static function sheets(array $blocks, bool $isDeload, WorkoutStatus $status): array
    {
        if ($isDeload || $status === WorkoutStatus::Skipped) {
            return [];
        }

        /** @var array<string, int> $last */
        $last = [];
        foreach ($blocks as $index => $block) {
            if ($block->sets > 0) {
                $last[$block->muscle->value] = $index;
            }
        }
        asort($last);

        $sheets = [];
        foreach ($last as $index) {
            $muscle = $blocks[$index]->muscle;
            $exercises = [];
            foreach ($blocks as $block) {
                if ($block->muscle === $muscle && $block->sets > 0) {
                    $exercises[$block->exerciseId] ??= $block->exerciseName;
                }
            }
            $sheets[] = new FeedbackSheet($muscle, $blocks[$index]->id, $blocks[$index]->isDone, $exercises);
        }

        return $sheets;
    }

    /**
     * FB-007: soreness is asked unless the muscle never trained here before this session, or its latest earlier
     * session started more than 14 days before this one.
     */
    public static function asksSoreness(DateTimeImmutable $start, ?DateTimeImmutable $lastTrained): bool
    {
        return $lastTrained !== null
            && $start->getTimestamp() - $lastTrained->getTimestamp() <= self::SORENESS_WINDOW_DAYS * 86400;
    }

    /**
     * WO-040: pump and volume, soreness when it is asked, and joint pain for every exercise of the sheet.
     *
     * @param  list<int>  $jointAnswered  the exercises with a joint answer
     */
    public static function isAnswered(FeedbackSheet $sheet, bool $asksSoreness, ?string $soreness, ?string $pump, ?string $volume, array $jointAnswered): bool
    {
        if ($pump === null || $volume === null || ($asksSoreness && $soreness === null)) {
            return false;
        }

        return array_diff(array_keys($sheet->exercises), $jointAnswered) === [];
    }
}
