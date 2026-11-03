<?php

namespace App\Capabilities\Reads\Data;

use App\Capabilities\Reads\Data\Today\Status;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

final class AgentStatusShowOutput extends CapabilityData
{
    public function __construct(
        /** Whether this instance runs the Coach (AGT-002). */
        public readonly bool $enabled,
        /** The user's own Coach switch (AGT-020). */
        public readonly bool $on,
        /** The user's newest conversation, sent with each message; null when they have none (AGT-024). */
        public readonly ?string $conversation_ulid,
        public readonly ?int $turns_left,
        public readonly ?int $tokens_left,
        public readonly bool $at_cap,
        public readonly ?string $resets_at,
        public readonly ?string $notice,
        /** The notice with its tone, picked here so a screen never derives it (CAP-018). */
        #[Field(of: Status::class)] public readonly ?Status $notice_status,
    ) {}
}
