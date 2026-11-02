<?php

namespace App\Capabilities\Today\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** Delete an off-plan entry (DAY-001). */
final class OffPlanDeleteInput extends CapabilityData
{
    public function __construct(
        public readonly string $id,
    ) {}
}
