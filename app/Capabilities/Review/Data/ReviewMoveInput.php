<?php

namespace App\Capabilities\Review\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Move calories from one day of next week to another (REV-004, NUT-021). */
final class ReviewMoveInput extends CapabilityData
{
    public function __construct(
        #[Field(minimum: 0, maximum: 6)]
        public readonly int $from_index,
        #[Field(minimum: 0, maximum: 6)]
        public readonly int $to_index,
        #[Field(minimum: 25, maximum: 500)]
        public readonly int $kcal,
    ) {}
}
