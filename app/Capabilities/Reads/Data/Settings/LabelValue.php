<?php

namespace App\Capabilities\Reads\Data\Settings;

use Rawphp\Capabilities\Support\CapabilityData;

/** A label and a display-ready value with its unit (CAP-015). */
final class LabelValue extends CapabilityData
{
    public function __construct(
        public readonly string $label,
        public readonly string $value,
    ) {}
}
