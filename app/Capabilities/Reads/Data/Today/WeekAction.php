<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** An entry of the week strip's menu (SCH-060, SCH-063). */
final class WeekAction extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['mark_away', 'edit_away', 'mark_rough', 'unmark_rough'])] public readonly string $action,
        public readonly string $label,
    ) {}
}
