<?php

namespace App\Capabilities\Plan\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One line of text in a list, such as a training default in words
 * ("Volume starts at MEV +2 sets", PH-008, TBL-012).
 */
final class PlanTextLine extends CapabilityData
{
    public function __construct(
        public readonly string $text,
    ) {}
}
