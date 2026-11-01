<?php

namespace App\Capabilities\Train\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A decided status for a pill, bar or banner (CAP-018): the words to show and
 * the tone to show them in (UI-004). The client never picks the tone.
 */
final class Pill extends CapabilityData
{
    public function __construct(
        public readonly string $text,
        #[Field(enum: ['good', 'neutral', 'warning', 'info'])]
        public readonly string $tone,
    ) {}
}
