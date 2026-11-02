<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Support\CapabilityData;

/** A meal the Adjust sheet can move calories out of or into: one not checked in. */
final class AdjustMealOption extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $time_text,
    ) {}
}
