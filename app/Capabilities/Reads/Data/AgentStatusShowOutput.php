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
        /** How much is left, or that a cap is reached, with its tone picked here (CAP-018). */
        #[Field(of: Status::class)] public readonly ?Status $notice,
    ) {}
}
