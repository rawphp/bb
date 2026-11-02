<?php

namespace App\Capabilities\Review\Data;

use App\Capabilities\Reads\Data\Today\Status;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Step 5: keep the goal, choose a new one or end the plan. Option ids are
 * `keep_goal`, `choose_new_goal` and `end_plan`; the last two open the phase
 * transition (PH-030).
 */
final class ReviewGoal extends CapabilityData
{
    /**
     * @param  list<Status>  $notes
     * @param  list<ReviewChoice>  $options
     */
    public function __construct(
        #[Field(items: Status::class)]
        public readonly array $notes,
        #[Field(items: ReviewChoice::class)]
        public readonly array $options,
    ) {}
}
