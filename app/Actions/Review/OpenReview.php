<?php

namespace App\Actions\Review;

use App\Domain\Review\ReviewMode;
use App\Domain\WeekBuilder\BuiltWeek;
use App\Domain\WeekBuilder\WeekBuildRefusal;
use App\Models\Plan;
use App\Models\ReviewAnswer;
use App\Models\User;
use App\Support\Review\ReviewAnalysis;
use App\Support\Review\ReviewAnswers;
use App\Support\Review\ReviewChoices;
use App\Support\Review\ReviewData;
use App\Support\Review\ReviewMessages;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * The weekly review the writes answer and save, and its stored answers as review_show and review_complete both read
 * them. {@see analysis()} is the review open for the user now for a week (REV-001); call it inside the write's
 * transaction: it locks the user's plan row, so the writes to one review run one after the other and a save never races
 * an answer or a second save.
 */
final class OpenReview
{
    public const NONE = 'There is no weekly review open for that week.';

    public const OUT_OF_DATE = 'Your review has changed since you answered. Choose next week\'s calories again.';

    public const NEEDS_AMOUNT = 'Enter how many calories a day you want for Choose my own before saving.';

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
     * The user's stored answers as review_show shows them and review_complete applies them, one reading for both: the
     * unanswered defaults when there are none, and `blocked`, the reason review_complete refuses them, which review_show
     * shows as the preview's error. A full review blocks a Choose my own draft with no amount (REV-010) and an answered
     * option it no longer offers. Stored moves that no longer build, though the option does (a day has passed since,
     * NUT-024), are left out with the reason, as review_answer leaves out a refused move.
     *
     * @return array{answers: ReviewAnswers, blocked: ?string}
     */
    public static function stored(User $user, ReviewAnalysis $analysis): array
    {
        $row = ReviewAnswer::forWeek($user, $analysis->period->reviewed->start->format('Y-m-d'));
        if ($row === null) {
            return ['answers' => ReviewAnswers::defaults(), 'blocked' => null];
        }
        $answers = $row->answers();
        $blocked = match (true) {
            $analysis->mode !== ReviewMode::Full => null,
            $row->needsAmount() => self::NEEDS_AMOUNT,
            ! self::offers($analysis, $row->option) => self::OUT_OF_DATE,
            default => null,
        };
        $with = fn (array $moves, ?string $refusal) => new ReviewAnswers(
            $answers->option, $answers->ownKcal, $answers->deloadToo, $answers->updateStepPlan, $moves, $refusal,
        );
        if ($blocked !== null) {
            return ['answers' => $with($answers->moves, $blocked), 'blocked' => $blocked];
        }
        if ($answers->moves !== []) {
            $week = (new ReviewChoices($analysis, $answers))->saveWeek();
            if ($week instanceof WeekBuildRefusal && (new ReviewChoices($analysis, $with([], null)))->saveWeek() instanceof BuiltWeek) {
                $answers = $with([], ReviewMessages::refusal($week));
            }
        }

        return ['answers' => $answers, 'blocked' => null];
    }

    /** The stored answers as review_show shows them ({@see stored()}). */
    public static function answers(User $user, ReviewAnalysis $analysis): ReviewAnswers
    {
        return self::stored($user, $analysis)['answers'];
    }
}
