<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** SCH-012: where the Now line sits on today's timeline, and the next item not yet done. */
final class TodayNow extends CapabilityData
{
    public function __construct(
        #[Field(description: 'The timeline entry the line sits before; null puts it after the last item.')] public readonly ?TimelineEntry $before,
        #[Field(description: '"Now"')] public readonly string $label,
        #[Field(description: '"Next: Dinner at 7:30 PM"; null when nothing later is still to do.')] public readonly ?string $next_text,
    ) {}
}
