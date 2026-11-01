<?php

namespace App\Capabilities\Reads\Data\Settings;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A status decided on the server: its words and its tone (CAP-018, UI-004). */
final class Status extends CapabilityData
{
    public function __construct(
        public readonly string $text,
        #[Field(enum: ['good', 'neutral', 'warning', 'info'])]
        public readonly string $tone,
    ) {}
}
