<?php

namespace App\Capabilities\Coach\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** coach_changes_list: the writes one Coach turn made, oldest first (AGT-013). */
final class CoachChangesListOutput extends CapabilityData
{
    /**
     * @param  list<CoachChange>  $changes
     */
    public function __construct(
        #[Field(items: CoachChange::class)]
        public readonly array $changes,
    ) {}
}
