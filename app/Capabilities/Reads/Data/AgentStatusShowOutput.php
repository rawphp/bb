<?php

namespace App\Capabilities\Reads\Data;

use Rawphp\Capabilities\Support\CapabilityData;

final class AgentStatusShowOutput extends CapabilityData
{
    public function __construct(
        public readonly bool $enabled,
        public readonly ?int $turns_left,
        public readonly ?int $tokens_left,
        public readonly bool $at_cap,
        public readonly ?string $resets_at,
        public readonly ?string $notice,
    ) {}
}
