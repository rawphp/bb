<?php

namespace App\Capabilities\Progress\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A chart range the user can pick: the current phase or the whole plan (ANA-001). */
final class ProgressScope extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['phase', 'plan'])]
        public readonly string $value,
        public readonly string $title,
        public readonly bool $selected,
    ) {}
}
