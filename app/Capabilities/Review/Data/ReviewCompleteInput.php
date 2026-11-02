<?php

namespace App\Capabilities\Review\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Save the review as answered: next week's targets, and any deload, diet break or step plan change. */
final class ReviewCompleteInput extends CapabilityData
{
    public function __construct(
        #[Field(format: 'date')]
        public readonly string $week_start,
    ) {}
}
