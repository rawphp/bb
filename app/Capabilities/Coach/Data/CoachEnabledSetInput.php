<?php

namespace App\Capabilities\Coach\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** coach_enabled_set: this user's Coach on or off (AGT-020). */
final class CoachEnabledSetInput extends CapabilityData
{
    public function __construct(
        public readonly bool $on,
    ) {}
}
