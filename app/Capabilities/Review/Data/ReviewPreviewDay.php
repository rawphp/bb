<?php

namespace App\Capabilities\Review\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A day of next week: calories, day type and workout (REV-003 step 7). */
final class ReviewPreviewDay extends CapabilityData
{
    public function __construct(
        #[Field(minimum: 0, maximum: 6)]
        public readonly int $index,
        #[Field(format: 'date')]
        public readonly string $date,
        public readonly string $date_label,
        #[Field(description: '"Training · Push A".')]
        public readonly string $detail,
        #[Field(description: '"2650 kcal"; null when the day has no target.')]
        public readonly ?string $kcal_label,
        #[Field(description: 'REV-004: calories can move to or from this day.')]
        public readonly bool $can_move,
        #[Field(description: 'The day is past, so it can\'t change.')]
        public readonly bool $is_locked,
    ) {}
}
