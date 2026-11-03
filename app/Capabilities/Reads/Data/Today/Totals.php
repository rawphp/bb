<?php

namespace App\Capabilities\Reads\Data\Today;

use App\Capabilities\Support\Status;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The day-totals bar (SCH-006, DAY-010, DAY-011, SAFE-018). */
final class Totals extends CapabilityData
{
    /**
     * @param  list<Status>  $warnings
     */
    public function __construct(
        #[Field(description: '"Hard", "Deload"…')] public readonly Status $day_type,
        #[Field(description: '"On track", "315 under"')] public readonly Status $status,
        public readonly Bar $calories,
        public readonly Bar $protein,
        #[Field(description: 'SAFE-018 nutrient warnings.', items: Status::class)] public readonly array $warnings,
        #[Field(description: 'DAY-002: the wand is on.')] public readonly bool $can_redistribute,
    ) {}
}
