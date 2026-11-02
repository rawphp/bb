<?php

namespace App\Capabilities\Reads\Data\Settings;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** One audited write (CAP-022), in plain words. */
final class ActivityEntry extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        #[Field(description: 'for example "Logged set 3 of Bench Press, 100 kg x 8"')]
        public readonly string $title,
        #[Field(enum: ['ui', 'agent', 'mcp', 'cli', 'bridge'])]
        public readonly string $channel,
        public readonly string $channel_label,
        #[Field(format: 'date-time')]
        public readonly string $occurred_at,
        public readonly string $when_text,
        public readonly Status $result,
    ) {}
}
