<?php

namespace App\Capabilities\Reads\Data\Today;

use App\Capabilities\Support\Status;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** What is being copied (MEAL-060, SCH-034). */
final class CopySource extends CapabilityData
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $time_text,
        public readonly string $foods_text,
        #[Field(description: '"Targets met" or "Targets not met"; null when copying a day.')] public readonly ?Status $status,
    ) {}
}
