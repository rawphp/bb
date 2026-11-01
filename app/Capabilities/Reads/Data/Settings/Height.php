<?php

namespace App\Capabilities\Reads\Data\Settings;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Height in the user's unit (SET-001): the text to show and the numbers the form edits. */
final class Height extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['cm', 'feetInches'])]
        public readonly string $unit,
        #[Field(description: 'for example "178 cm" or "5 ft 10 in"')]
        public readonly string $text,
        public readonly ?int $cm,
        public readonly ?int $feet,
        public readonly ?int $inches,
    ) {}
}
