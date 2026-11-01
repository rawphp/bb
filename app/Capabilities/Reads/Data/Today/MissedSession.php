<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The missed-day prompt (SCH-057). */
final class MissedSession extends CapabilityData
{
    /**
     * @param  list<MissedChoice>  $choices
     */
    public function __construct(
        public readonly string $mesocycle_id,
        public readonly int $week,
        public readonly int $day,
        public readonly string $title,
        public readonly string $text,
        #[Field(items: MissedChoice::class)] public readonly array $choices,
    ) {}
}
