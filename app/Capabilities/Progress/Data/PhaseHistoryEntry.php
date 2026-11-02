<?php

namespace App\Capabilities\Progress\Data;

use App\Capabilities\Support\Line;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A past phase: dates, type, weight change, average rate, and its mesocycles (ANA-006, ANA-008). */
final class PhaseHistoryEntry extends CapabilityData
{
    /**
     * @param  list<Line>  $lines
     * @param  list<Line>  $mesocycles  name and length of each mesocycle run in the phase
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $dates_label,
        #[Field(items: Line::class)]
        public readonly array $lines,
        #[Field(items: Line::class)]
        public readonly array $mesocycles,
    ) {}
}
