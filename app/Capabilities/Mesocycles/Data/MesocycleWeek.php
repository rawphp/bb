<?php

namespace App\Capabilities\Mesocycles\Data;

use App\Capabilities\Train\Data\Pill;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One week's column (MESO-040, MESO-041): title ("Week 4", "Deload"), the Now and
 * Diet break pills, the mean daily calorie target, sets per muscle and the days.
 */
final class MesocycleWeek extends CapabilityData
{
    /**
     * @param  list<MesocycleMuscleSets>  $muscle_sets
     * @param  list<MesocycleDay>  $days
     */
    public function __construct(
        public readonly int $number,
        public readonly string $title,
        public readonly ?Pill $now,
        public readonly ?Pill $diet_break,
        public readonly ?string $calories_text,
        #[Field(items: MesocycleMuscleSets::class)]
        public readonly array $muscle_sets,
        #[Field(items: MesocycleDay::class)]
        public readonly array $days,
    ) {}
}
