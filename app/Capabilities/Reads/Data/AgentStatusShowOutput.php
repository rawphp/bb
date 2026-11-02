<?php

namespace App\Capabilities\Reads\Data;

use App\Capabilities\Reads\Data\Today\Status;
use Rawphp\Capabilities\Attributes\Field;
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
        /** The notice with its tone, picked here so a screen never derives it (CAP-018). */
        #[Field(of: Status::class)] public readonly ?Status $notice_status,
    ) {}
}
