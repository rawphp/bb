<?php

namespace App\Capabilities\Progress\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A past mesocycle: dates, phase and average weekly sets per muscle (ANA-007). */
final class MesocycleHistoryEntry extends CapabilityData
{
    /** @param  list<Line>  $sets */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $dates_label,
        #[Field(description: 'The phase it ran in, null when none.')]
        public readonly ?Status $phase,
        #[Field(description: '"Average sets per week", or "No sets logged" when sets is empty.')]
        public readonly string $sets_title,
        #[Field(items: Line::class)]
        public readonly array $sets,
    ) {}
}
