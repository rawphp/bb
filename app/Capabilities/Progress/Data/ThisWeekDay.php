<?php

namespace App\Capabilities\Progress\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** One day of the plan week on the strip (ANA-003, ANA-011). */
final class ThisWeekDay extends CapabilityData
{
    public function __construct(
        #[Field(format: 'date')]
        public readonly string $date,
        public readonly string $letter,
        public readonly string $day_label,
        #[Field(description: 'The day\'s calorie target, null when the day has none.')]
        public readonly ?string $calories_label,
        #[Field(description: 'ANA-011: the green adherence dot.')]
        public readonly bool $is_complete,
        public readonly bool $is_today,
    ) {}
}
