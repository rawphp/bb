<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A template laid out for reading (MESO-012, MESO-015): days with exercises by
 * muscle slot, muscle priorities and weekly sets per muscle.
 */
final class TemplateDetail extends CapabilityData
{
    /**
     * @param  list<TemplateDay>  $days
     * @param  list<MesocyclePriorityRow>  $priorities
     * @param  list<TemplateWeeklySets>  $weekly_sets
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $summary,
        #[Field(items: TemplateDay::class)]
        public readonly array $days,
        #[Field(items: MesocyclePriorityRow::class)]
        public readonly array $priorities,
        #[Field(items: TemplateWeeklySets::class)]
        public readonly array $weekly_sets,
    ) {}
}
