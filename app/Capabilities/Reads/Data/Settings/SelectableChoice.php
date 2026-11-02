<?php

namespace App\Capabilities\Reads\Data\Settings;

use Rawphp\Capabilities\Support\CapabilityData;

/** An option that is on or off, such as a diet filter. */
final class SelectableChoice extends CapabilityData
{
    public function __construct(
        public readonly string $value,
        public readonly string $label,
        public readonly bool $selected,
    ) {}
}
