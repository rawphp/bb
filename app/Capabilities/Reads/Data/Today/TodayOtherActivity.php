<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** An Other activity card (SCH-032). */
final class TodayOtherActivity extends CapabilityData
{
    /**
     * @param  list<ActivityMenuItem>  $menu
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        #[Field(description: '"6:00 PM · 45 min"')] public readonly string $time_text,
        #[Field(description: '"Moderate"')] public readonly string $intensity_text,
        #[Field(items: ActivityMenuItem::class)] public readonly array $menu,
    ) {}
}
