<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A saved mesocycle (MESO-040 to MESO-046): phase, length stepper (MESO-042), the
 * end prompt when it can end (MESO-043, MESO-044), a column per week, and the muscle
 * priorities for Progression rates (MESO-030 to MESO-034).
 */
final class MesocycleDetail extends CapabilityData
{
    /**
     * @param  list<MesocycleWeek>  $weeks
     * @param  list<MesocyclePriorityRow>  $priorities
     */
    public function __construct(
        public readonly string $phase_title,
        public readonly string $length_text,
        public readonly int $weeks_count,
        public readonly bool $can_shorten,
        public readonly bool $can_lengthen,
        public readonly bool $can_add_day,
        public readonly ?MesocycleEndPrompt $end,
        #[Field(items: MesocycleWeek::class)]
        public readonly array $weeks,
        #[Field(items: MesocyclePriorityRow::class)]
        public readonly array $priorities,
    ) {}
}
