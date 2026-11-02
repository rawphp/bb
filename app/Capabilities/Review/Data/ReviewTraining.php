<?php

namespace App\Capabilities\Review\Data;

use App\Capabilities\Reads\Data\Today\Status;
use App\Capabilities\Support\Line;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Step 3: sessions done, skipped and missed, sets against planned, and the
 * underperformance, volume ceiling and joint-pain notes.
 */
final class ReviewTraining extends CapabilityData
{
    /**
     * @param  list<Line>  $lines
     * @param  list<Status>  $notes
     */
    public function __construct(
        #[Field(items: Line::class)]
        public readonly array $lines,
        #[Field(items: Status::class)]
        public readonly array $notes,
        public readonly string $next_week_label,
        #[Field(description: 'Normal, last hard week or deload.')]
        public readonly Status $next_week,
    ) {}
}
