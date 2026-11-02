<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The active-rest offer of the next-mesocycle card. */
final class ActiveRest extends CapabilityData
{
    public function __construct(
        public readonly string $text,
        #[Field(description: 'The start once active rest is on: "Starts Monday, Nov 16".')] public readonly string $start_text,
        #[Field(format: 'date')] public readonly string $start_date,
    ) {}
}
