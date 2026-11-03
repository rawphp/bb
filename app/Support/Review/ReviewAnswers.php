<?php

namespace App\Support\Review;

use App\Domain\WeekBuilder\CalorieMove;

/**
 * What the user has answered in the weekly review so far (REV-031: nothing is applied until review_complete). The
 * review_show builders render any answers; review_show itself passes {@see defaults()}, and review_answer feeds the
 * ones the user sent.
 *
 * {@see defaults()} is the unanswered state, and it selects the recommended option (the Swift review opens on
 * `layout.recommended`). Answers made with `new` are what the user answered and are taken literally: #87 stores the
 * option the screen showed, even when it is `recommendation` and Drop fatigue was the recommended one.
 */
final readonly class ReviewAnswers
{
    /**
     * @param  string  $option  a calorie option id: recommendation, repeat_this_week, choose_own, drop_fatigue, cut_calories or add_steps
     * @param  ?int  $ownKcal  the average kcal a day for choose_own; null for the suggested amount
     * @param  ?bool  $deloadToo  REV-011: the answer to "Make this your deload week too?"; null for the default
     * @param  bool  $updateStepPlan  REV-050
     * @param  list<CalorieMove>  $moves  REV-004, applied in order
     * @param  ?string  $refusal  why the last move was refused, shown as the preview's error
     */
    public function __construct(
        public string $option,
        public ?int $ownKcal,
        public ?bool $deloadToo,
        public bool $updateStepPlan,
        public array $moves,
        public ?string $refusal,
    ) {}

    /**
     * Nothing answered yet: the recommendation, the suggested amounts, no moves. It is one shared instance, so callers
     * tell "unanswered" from an answer that happens to hold the same values with `=== ReviewAnswers::defaults()`.
     */
    public static function defaults(): self
    {
        static $unanswered = null;

        return $unanswered ??= new self('recommendation', null, null, false, [], null);
    }
}
