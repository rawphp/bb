<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A day the workout can move to (SCH-051, SCH-038). */
final class MoveDayOption extends CapabilityData
{
    public function __construct(
        #[Field(format: 'date')] public readonly string $date,
        #[Field(description: '"Thu, Nov 5"')] public readonly string $label,
        public readonly bool $enabled,
        #[Field(description: 'Why the day is not offered.')] public readonly ?string $note,
    ) {}
}
