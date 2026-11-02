<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Support\CapabilityData;

/** A meal the Redistribute picker lists, with what it would hold (DAY-002). */
final class RedistributeRow extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $time_text,
        public readonly bool $selected,
        public readonly string $kcal_text,
        public readonly string $protein_text,
    ) {}
}
