<?php

namespace App\Capabilities\Reads\Data;

use App\Capabilities\Reads\Data\Today\Status;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** agent_status_show: whether the Coach is available and on, and its Settings page (AGT-002, AGT-005, AGT-020). */
final class AgentStatusShowOutput extends CapabilityData
{
    public function __construct(
        #[Field(description: 'the instance has the agent on (AGT-002)')]
        public readonly bool $available,
        #[Field(description: 'available and this user has not turned it off (AGT-020)')]
        public readonly bool $on,
        public readonly Status $status,
        #[Field(description: 'what is left of the caps; null when uncapped (AGT-005)')]
        public readonly ?Status $usage,
        #[Field(description: 'what the Coach can and cannot do (AGT-020)')]
        public readonly string $note,
        public readonly bool $has_conversation,
    ) {}
}
