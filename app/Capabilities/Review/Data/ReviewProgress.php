<?php

namespace App\Capabilities\Review\Data;

use App\Capabilities\Reads\Data\Today\Status;
use App\Capabilities\Support\Line;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Step 4: trend weight against the goal range, and the week's rate against target. */
final class ReviewProgress extends CapabilityData
{
    /**
     * @param  list<Line>  $lines
     * @param  list<Status>  $pills
     */
    public function __construct(
        #[Field(items: Line::class)]
        public readonly array $lines,
        #[Field(items: Status::class)]
        public readonly array $pills,
        #[Field(description: 'Shown when the phase has too little trend weight; null otherwise.')]
        public readonly ?string $empty_message,
    ) {}
}
