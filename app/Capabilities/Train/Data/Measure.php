<?php

namespace App\Capabilities\Train\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A number ready to show (CAP-015): already in the user's units and rounded the
 * way the spec says. `text` is what the screen prints ("142.5 lb"); `value` and
 * `unit` fill an input or a lookup, never a calculation.
 */
final class Measure extends CapabilityData
{
    public function __construct(
        public readonly float $value,
        public readonly string $unit,
        public readonly string $text,
    ) {}
}
