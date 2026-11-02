<?php

namespace App\Capabilities\Reads\Data\Settings;

use Rawphp\Capabilities\Support\CapabilityData;

/** The two unit settings (SET-001). */
final class Units extends CapabilityData
{
    public function __construct(
        public readonly ChoiceSetting $weight,
        public readonly ChoiceSetting $height,
    ) {}
}
