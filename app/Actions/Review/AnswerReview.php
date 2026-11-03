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
 * the review does not offer (REV-010, REV-012, REV-027), or an amount sent alone with no Choose my own to set. An answer it can take is
 * stored even when next week will not build with it, with the reason (NUT-022), as rp-clone's moveCalories refuses a
 * move: the moves stay at the last set that built, and an option that will not build itself is kept as sent.
 *
 * Choose my own with no amount is stored as sent, a draft that review_complete refuses until an amount comes
 * ({@see ReviewAnswer::needsAmount()}). The amount may come alone, with no option: it is set on the stored Choose my
 * own, which keeps its other answers and drops its moves, as a new amount does on the screen. The suggested amount
 * review_show fills a draft's field with, sent back with another change (the deload answer, the step offer, a move),
 * leaves the draft a draft.
 */
final class AnswerReview
{
    public const NOT_OFFERED = 'That option is not one this review offers.';

    public const NO_AMOUNT = 'Enter the average calories a day you want, as a whole number above 0.';

    public const AMOUNT_ONLY = 'Choose an option. An answer without one can only send the amount for Choose my own.';

    /** @throws DomainException when the answer cannot be taken (422) */
    public function __invoke(User $user, ReviewAnswerInput $input): ReviewAnswer
    {
        return DB::transaction(fn (): ReviewAnswer => $this->answer($user, $input));
    }

    private function answer(User $user, ReviewAnswerInput $input): ReviewAnswer
    {
        $analysis = OpenReview::analysis($user, $input->week_start);
        $row = ReviewAnswer::forWeek($user, $input->week_start, lock: true);

        if ($input->option === null) {
            if ($input->own_kcal === null || $row?->option !== 'choose_own') {
                throw new DomainException(self::AMOUNT_ONLY);
            }
            [$option, $deloadToo, $updateStepPlan, $sent] = ['choose_own', $row->deload_too, $row->update_step_plan, []];
        } else {
            [$option, $deloadToo, $updateStepPlan] = [$input->option, $input->deload_too, $input->update_step_plan];
            $sent = array_map(fn (ReviewMoveInput $move) => ['from_index' => $move->from_index, 'to_index' => $move->to_index, 'kcal' => $move->kcal], $input->moves);
        }
        if (! OpenReview::offers($analysis, $option)) {
            throw new DomainException(self::NOT_OFFERED);
        }
        $ownKcal = $input->own_kcal;
        // REV-010: review_show fills a draft's field with the suggested amount, and the screen sends the field back with
        // every other answer: the deload answer, the step offer, a move. That echo is not an amount the user typed, so
        // the draft stays one. The amount counts when it is the answer's only change, or when it is sent alone.
        $echo = $row !== null && ($deloadToo !== $row->deload_too || $updateStepPlan !== $row->update_step_plan || ($sent !== [] && $sent != $row->moves));
        if ($input->option === 'choose_own' && $row?->needsAmount() && $echo && $ownKcal === self::prefill($analysis, $row)) {
            $ownKcal = null;
        }
        $answers = fn (array $moves) => new ReviewAnswers(
            $option, $ownKcal, $deloadToo, $updateStepPlan, ReviewAnswer::calorieMoves($moves), null,
        );

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
            'option' => $option,
            'own_kcal' => $ownKcal,
            'deload_too' => $deloadToo,
            'update_step_plan' => $updateStepPlan,
            'moves' => $moves,
            'refusal' => $refusal,
        ])->save();

        return $row;
    }

    /** The amount review_show shows in a draft's own-calories field, as ReviewCaloriesPayload rounds it. */
    private static function prefill(ReviewAnalysis $analysis, ReviewAnswer $draft): ?int
    {
        $own = (new ReviewChoices($analysis, $draft->answers()))->ownKcal();

        return $own === null ? null : (int) round($own);
    }

    /**
     * Why next week will not build with these answers; null when it builds.
     *
     * @throws DomainException when the option stands for no calories
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
