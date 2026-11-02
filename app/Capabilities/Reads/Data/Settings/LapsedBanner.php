<?php

namespace App\Capabilities\Reads\Data\Settings;

use Rawphp\Capabilities\Support\CapabilityData;

/** The banner over every tab once a subscription has lapsed (SUB-010). */
final class LapsedBanner extends CapabilityData
{
    public function __construct(
        public readonly string $text,
        public readonly string $action_label,
    ) {}
}
