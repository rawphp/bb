<?php

namespace App\Capabilities\Review\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * The weekly review (REV-001 to REV-050) as last answered with review_answer.
 * A step the mode leaves out is null (REV-005).
 */
final class ReviewShowOutput extends CapabilityData
{
    /** @param  list<ReviewStep>  $steps */
    public function __construct(
        #[Field(enum: ['ready', 'unavailable'])]
        public readonly string $state,
        #[Field(description: 'Why there is nothing to review; null when ready.')]
        public readonly ?string $message,
        #[Field(description: 'The reviewed week; review_answer and review_complete send it back.', format: 'date')]
        public readonly ?string $week_start,
        #[Field(description: '"5 Oct to 11 Oct".')]
        public readonly string $week_label,
        #[Field(items: ReviewStep::class)]
        public readonly array $steps,
        public readonly ?ReviewWeighIns $weigh_ins,
        public readonly ?ReviewMeals $meals,
        public readonly ?ReviewTraining $training,
        public readonly ?ReviewProgress $progress,
        public readonly ?ReviewGoal $goal,
        public readonly ?ReviewCalories $calories,
        public readonly ?ReviewPreview $preview,
        public readonly bool $can_save,
        public readonly string $save_title,
    ) {}
}
