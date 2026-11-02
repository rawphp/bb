<?php

namespace App\Capabilities\Reads\Data\Settings;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The bridge's last post and what it carried (INT-004). */
final class BridgePost extends CapabilityData
{
    /**
     * @param  list<LabelValue>  $items
     */
    public function __construct(
        public readonly string $when_text,
        #[Field(items: LabelValue::class)]
        public readonly array $items,
    ) {}
}
