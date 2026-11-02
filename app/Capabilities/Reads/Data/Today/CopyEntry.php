<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** One destination of the shown day in time order; meals and workout blocks (MEAL-060). */
final class CopyEntry extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['meal', 'workout'])] public readonly string $kind,
        public readonly string $id,
    ) {}
}
