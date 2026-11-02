<?php

namespace App\Capabilities\Reads\Data\Settings;

use Rawphp\Capabilities\Support\CapabilityData;

/** One option of a picker: the value to send back and its label. */
final class Choice extends CapabilityData
{
    public function __construct(
        public readonly string $value,
        public readonly string $label,
    ) {}
}
