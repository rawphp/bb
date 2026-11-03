<?php

namespace App\Actions\Review;

use App\Capabilities\Review\Data\ReviewAnswerInput;
use App\Capabilities\Review\Data\ReviewMoveInput;
use App\Domain\WeekBuilder\WeekBuildRefusal;
use App\Models\ReviewAnswer;
use App\Models\User;
use App\Support\Review\ReviewAnalysis;
use App\Support\Review\ReviewAnswers;
use App\Support\Review\ReviewChoices;
use App\Support\Review\ReviewMessages;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Stores the weekly review's answers so far (REV-004, REV-031): one row per user per reviewed week, replaced whole by
 * each answer. Nothing is applied until review_complete.
 *
 * An answer the review cannot take is refused and nothing is written: a week other than the one open now, an option
 * the review does not offer (REV-010, REV-012, REV-027), or Choose my own with no amount. An answer it can take is
 * stored even when next week will not build with it, with the reason (NUT-022), as rp-clone's moveCalories refuses a
 * move: the moves stay at the last set that built, and an option that will not build itself is kept as sent.
 */
final class AnswerReview
{
    public const NOT_OFFERED = 'That option is not one this review offers.';

    public const NO_AMOUNT = 'Enter the average calories a day you want.';

    /** @throws DomainException when the answer cannot be taken (422) */
    public function __invoke(User $user, ReviewAnswerInput $input): ReviewAnswer
    {
        return DB::transaction(fn (): ReviewAnswer => $this->answer($user, $input));
    }

    private function answer(User $user, ReviewAnswerInput $input): ReviewAnswer
    {
        $analysis = OpenReview::analysis($user, $input->week_start);
        $sent = array_map(fn (ReviewMoveInput $move) => ['from_index' => $move->from_index, 'to_index' => $move->to_index, 'kcal' => $move->kcal], $input->moves);
        $answers = fn (array $moves) => new ReviewAnswers(
            $input->option, $input->own_kcal, $input->deload_too, $input->update_step_plan, ReviewAnswer::calorieMoves($moves), null,
        );

        // An away or training-only review lists no calorie option, so it answers only under the unanswered one.
        $layout = (new ReviewChoices($analysis, $answers([])))->layout();
        if (! in_array($input->option, $layout['ids'] ?? [ReviewAnswers::defaults()->option], true)) {
            throw new DomainException(self::NOT_OFFERED);
        }

        if ($input->option === 'choose_own' && $input->own_kcal === null) {
            throw new DomainException(self::NO_AMOUNT);
        }

        $row = ReviewAnswer::forWeek($user, $input->week_start, lock: true);
        $moves = $sent;
        $refusal = self::refusal($analysis, $answers([]));
        if ($refusal === null && $sent !== []) {
            $refusal = self::refusal($analysis, $answers($sent));
            if ($refusal !== null) {
                // The last moves that built, while they still build with this answer.
                $kept = $row->moves ?? [];
                $moves = $kept !== [] && self::refusal($analysis, $answers($kept)) === null ? $kept : [];
            }
        }

        $row ??= new ReviewAnswer;
        $row->user_id = $user->id;
        $row->week_start = $input->week_start;
        $row->fill([
            'option' => $input->option,
            'own_kcal' => $input->own_kcal,
            'deload_too' => $input->deload_too,
            'update_step_plan' => $input->update_step_plan,
            'moves' => $moves,
            'refusal' => $refusal,
        ])->save();

        return $row;
    }

    /**
     * Why next week will not build with these answers; null when it builds.
     *
     * @throws DomainException when the option stands for no calories: Choose my own with no amount
     */
    private static function refusal(ReviewAnalysis $analysis, ReviewAnswers $answers): ?string
    {
        $week = (new ReviewChoices($analysis, $answers))->saveWeek();
        if ($week === null) {
            throw new DomainException(self::NO_AMOUNT);
        }

        return $week instanceof WeekBuildRefusal ? ReviewMessages::refusal($week) : null;
    }
}
