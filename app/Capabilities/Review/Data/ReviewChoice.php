<?php

namespace App\Capabilities\Review\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** One option of the goal or calories step. */
final class ReviewChoice extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        #[Field(description: 'REV-013: next week\'s calories and macros, then the option\'s own words or why it is refused. Lines split on "\n".')]
        public readonly string $detail,
        public readonly bool $is_recommended,
        public readonly bool $is_selected,
        #[Field(description: 'False when safety refuses it (SAFE-008 to SAFE-012); detail says why.')]
        public readonly bool $is_enabled,
    ) {}
}
