<?php

namespace App\Capabilities\Coach\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** coach_changes_list: the ULID of one of the user's Coach turns (AGT-013). */
final class CoachChangesListInput extends CapabilityData
{
    public function __construct(
        public readonly string $turn,
    ) {}
}
