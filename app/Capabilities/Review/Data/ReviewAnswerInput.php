<?php

namespace App\Capabilities\Review\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * The review's answers so far, sent whole each time: the calorie option, its
 * own amount, the deload answer, the step plan offer and the calorie moves.
 * Nothing is applied until review_complete (REV-031).
 */
final class ReviewAnswerInput extends CapabilityData
{
    /** @param  list<ReviewMoveInput>  $moves */
    public function __construct(
        #[Field(format: 'date')]
        public readonly string $week_start,
        #[Field(enum: ['recommendation', 'repeat_this_week', 'choose_own', 'drop_fatigue', 'cut_calories', 'add_steps'])]
        public readonly string $option,
        #[Field(description: 'Average kcal a day for choose_own.', minimum: 0)]
        public readonly ?int $own_kcal = null,
        #[Field(description: 'REV-011: "Make this your deload week too?"; null for the default.')]
        public readonly ?bool $deload_too = null,
        #[Field(description: 'REV-050: update the step plan to the walked average.')]
        public readonly bool $update_step_plan = false,
        #[Field(description: 'REV-004: calorie moves, applied in order.', items: ReviewMoveInput::class)]
        public readonly array $moves = [],
    ) {}

    public static function rules(): array
    {
        return ['week_start' => ['date_format:Y-m-d']];
    }
}
