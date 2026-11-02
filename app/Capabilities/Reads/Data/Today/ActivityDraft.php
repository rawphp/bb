<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The stored Other activity the Add workout sheet edits (SCH-032, SCH-038). */
final class ActivityDraft extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        #[Field(description: '24-hour "HH:MM".')] public readonly string $start,
        public readonly int $minutes,
        #[Field(enum: ['light', 'moderate', 'hard'])] public readonly string $intensity,
    ) {}
}
