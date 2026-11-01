<?php

namespace App\Capabilities\Reads\Data;

use Rawphp\Capabilities\Support\CapabilityData;

final class PushShowOutput extends CapabilityData
{
    public function __construct(
        public readonly bool $available,
        public readonly ?string $public_key,
        public readonly int $devices,
    ) {}
}
