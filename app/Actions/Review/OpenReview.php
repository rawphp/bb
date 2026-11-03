<?php

namespace App\Actions\Review;

use App\Models\Plan;
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
     * The calorie option ids the review offers (REV-010, REV-012, REV-027). An away or training-only review lists none,
     * so it takes only the unanswered one.
     *
     * @return list<string>
     */
    public static function offered(ReviewAnalysis $analysis): array
    {
        return (new ReviewChoices($analysis, ReviewAnswers::defaults()))->layout()['ids'] ?? [ReviewAnswers::defaults()->option];
    }
}
