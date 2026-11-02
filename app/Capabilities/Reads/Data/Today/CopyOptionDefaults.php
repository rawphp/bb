<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Support\CapabilityData;

/** The Copy sheet's advanced options as they open (MEAL-060). */
final class CopyOptionDefaults extends CapabilityData
{
    public function __construct(
        public readonly bool $update_target_macros,
        public readonly bool $update_meal_times,
        public readonly bool $copy_locked_state,
    ) {}
}
