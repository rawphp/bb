<?php

namespace App\Capabilities\Review\Data;

use App\Capabilities\Progress\Data\Status;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Step 1: the week's weigh-ins, missing ones in orange, with late adds through weigh_in_log. */
final class ReviewWeighIns extends CapabilityData
{
    /** @param  list<ReviewWeighInRow>  $rows */
    public function __construct(
        #[Field(items: ReviewWeighInRow::class)]
        public readonly array $rows,
        #[Field(description: 'Shown when nothing was scheduled or logged; null otherwise.')]
        public readonly ?string $empty_message,
        #[Field(description: '"Missing · Add", on each row marked is_missing.')]
        public readonly Status $missing,
        public readonly string $add_title,
    ) {}
}
