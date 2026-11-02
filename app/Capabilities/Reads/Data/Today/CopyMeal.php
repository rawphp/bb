<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A destination meal (MEAL-060). */
final class CopyMeal extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $time_text,
        #[Field(description: '"512 kcal · P 40 F 12 C 60"')] public readonly string $target_text,
        public readonly string $foods_text,
        public readonly bool $enabled,
        #[Field(description: 'Why it cannot take the copy.')] public readonly ?string $note,
    ) {}
}
