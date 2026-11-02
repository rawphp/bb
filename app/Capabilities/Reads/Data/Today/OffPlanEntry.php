<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Support\CapabilityData;

/** Food eaten off-plan, under the day's items (DAY-001). */
final class OffPlanEntry extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $time_text,
        public readonly MacroLine $macros,
    ) {}
}
