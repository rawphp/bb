<?php

namespace App\Capabilities\Reads\Data\Settings;

use Rawphp\Capabilities\Support\CapabilityData;

/** The Health bridge token, without its secret. */
final class BridgeToken extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $expires_text,
        public readonly string $last_used_text,
    ) {}
}
