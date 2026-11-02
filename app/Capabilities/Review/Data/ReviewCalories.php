<?php

namespace App\Capabilities\Review\Data;

use App\Capabilities\Reads\Data\Today\Status;
use App\Capabilities\Support\Line;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Step 6 (REV-010 to REV-013, REV-026, REV-027, REV-041 to REV-050): last
 * week's target and average, the reasons, and the options. Option ids are
 * `recommendation`, `repeat_this_week`, `choose_own`, `drop_fatigue`, and on a
 * stall `cut_calories` and `add_steps`.
 */
final class ReviewCalories extends CapabilityData
{
    /**
     * @param  list<Line>  $lines
     * @param  list<Status>  $notes
     * @param  list<ReviewChoice>  $options
     */
    public function __construct(
        #[Field(items: Line::class)]
        public readonly array $lines,
        #[Field(items: Status::class)]
        public readonly array $notes,
        #[Field(items: ReviewChoice::class)]
        public readonly array $options,
        #[Field(description: 'Shown while choose_own is selected; null otherwise.')]
        public readonly ?ReviewOwnCalories $own,
        #[Field(description: 'Shown while drop_fatigue is selected; null otherwise.')]
        public readonly ?ReviewDeload $deload,
        public readonly ?ReviewStepOffer $step_offer,
    ) {}
}
