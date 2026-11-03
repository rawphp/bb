<?php

namespace App\Capabilities\Review\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * The review's answers so far, sent whole each time: the calorie option, its
 * own amount, the deload answer, the step plan offer and the calorie moves.
 * Only an amount may come alone, for the stored choose_own. Nothing is
 * applied until review_complete (REV-031).
 */
final class ReviewAnswerInput extends CapabilityData
{
    /** @param  list<ReviewMoveInput>  $moves */
    public function __construct(
        #[Field(format: 'date')]
        public readonly string $week_start,
        #[Field(description: 'The calorie option; left out, the answer sets own_kcal on the stored choose_own and clears its moves, as a new amount does.', enum: ['recommendation', 'repeat_this_week', 'choose_own', 'drop_fatigue', 'cut_calories', 'add_steps'])]
        public readonly ?string $option = null,
        #[Field(description: 'Average kcal a day for choose_own; null leaves it to be sent, and review_complete waits for it. While choose_own waits for it, the suggested amount sent with a new step offer or deload answer, or new moves, is read as not yet chosen; send it alone, or with no change but cleared moves, to choose it.', minimum: 1, maximum: 10000)]
        public readonly ?int $own_kcal = null,
        #[Field(description: 'REV-011: "Make this your deload week too?"; null for the default.')]
        public readonly ?bool $deload_too = null,
        #[Field(description: 'REV-050: update the step plan to the walked average.')]
        public readonly bool $update_step_plan = false,
        #[Field(description: 'REV-004: calorie moves, applied in order.', items: ReviewMoveInput::class)]
        public readonly array $moves = [],
    ) {}

    /** option may be null as well as left out, for an amount sent alone. */
    public static function jsonSchema(): array
    {
        $schema = parent::jsonSchema();
        $schema['properties']['option']['enum'][] = null;

        return $schema;
    }
}
