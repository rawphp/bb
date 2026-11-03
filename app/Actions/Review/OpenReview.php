<?php

namespace App\Actions\Review;

use App\Domain\Review\ReviewMode;
use App\Models\Plan;
use App\Models\ReviewAnswer;
use App\Models\User;
use App\Support\Review\ReviewAnalysis;
use App\Support\Review\ReviewAnswers;
use App\Support\Review\ReviewChoices;
use App\Support\Review\ReviewData;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * REV-001: the weekly review a write may answer or save, the one open for the user now for the week starting
 * `$weekStart`. Call it inside the write's transaction: it locks the user's plan row, so the writes to one review run
 * one after the other and a save never races an answer or a second save.
 */
final class OpenReview
{
    public const NONE = 'There is no weekly review open for that week.';

    public const OUT_OF_DATE = 'Your review has changed since you answered. Choose next week\'s calories again.';

    /** @throws DomainException when no review is open for that week (422) */
    public static function analysis(User $user, string $weekStart): ReviewAnalysis
    {
        Plan::query()->where('user_id', $user->id)->lockForUpdate()->first();
        $analysis = ReviewAnalysis::fromData(ReviewData::load($user, CarbonImmutable::now()));
        if ($analysis === null || $analysis->period->reviewed->start->format('Y-m-d') !== $weekStart) {
            throw new DomainException(self::NONE);
        }

        return $analysis;
    }

    /**
     * Whether the review offers the calorie option `$option` (REV-010, REV-012, REV-027). An away or training-only review
     * lists none, so it takes only the unanswered one.
     */
    public static function offers(ReviewAnalysis $analysis, string $option): bool
    {
        $ids = (new ReviewChoices($analysis, ReviewAnswers::defaults()))->layout()['ids'] ?? [ReviewAnswers::defaults()->option];

        return in_array($option, $ids, true);
    }

    /**
     * The user's stored answers as review_show shows them: the unanswered defaults when there are none, and in a full
     * review an answered option it no longer offers carries the refusal review_complete gives it.
     */
    public static function answers(User $user, ReviewAnalysis $analysis): ReviewAnswers
    {
        $row = ReviewAnswer::forWeek($user, $analysis->period->reviewed->start->format('Y-m-d'));
        if ($row === null) {
            return ReviewAnswers::defaults();
        }
        $answers = $row->answers();
        if ($analysis->mode !== ReviewMode::Full || self::offers($analysis, $row->option)) {
            return $answers;
        }

        return new ReviewAnswers($answers->option, $answers->ownKcal, $answers->deloadToo, $answers->updateStepPlan, $answers->moves, self::OUT_OF_DATE);
    }
}
