<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** An answer to the missed-day prompt (SCH-057). */
final class MissedChoice extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['do_it_today', 'next_rest_day', 'skip'])] public readonly string $choice,
        public readonly string $label,
        #[Field(description: 'The day the session moves to; null for Skip.', format: 'date')] public readonly ?string $date,
    ) {}
}
