<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The weigh-in row (SCH-004). */
final class TodayWeighIn extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        public readonly string $time_text,
        #[Field(description: '"82.4 kg" in the user\'s unit; null shows "Add weigh-in".')] public readonly ?string $weight_text,
    ) {}
}
