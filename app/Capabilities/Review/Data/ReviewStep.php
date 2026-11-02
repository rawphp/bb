<?php

namespace App\Capabilities\Review\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** One screen of the review (REV-003). Training-only mode has steps 1, 3 and 4 (REV-005). */
final class ReviewStep extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['weigh_ins', 'meals', 'training', 'progress', 'goal', 'calories', 'preview'])]
        public readonly string $key,
        public readonly string $title,
    ) {}
}
