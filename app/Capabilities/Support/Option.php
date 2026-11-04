<?php

namespace App\Capabilities\Support;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One option of a picker: the value to send back and its label ("Front delts").
 * A dropdown option, not the UI-006 choice-list row (that is `Choice`).
 */
final class Option extends CapabilityData
{
    public function __construct(
        public readonly string $value,
        public readonly string $label,
    ) {}
}
