<?php

namespace App\Capabilities\Support;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One row of a choice list (UI-006). A write sends `value` back as it came.
 * `detail` is "" when there is none.
 */
final class Choice extends CapabilityData
{
    public function __construct(
        public readonly string $value,
        public readonly string $title,
        #[Field(description: 'The row\'s explanation, or why it is refused. Lines split on "\n".')]
        public readonly string $detail,
        public readonly bool $is_recommended,
        public readonly bool $is_selected,
        #[Field(description: 'False when the server refuses the option; detail says why.')]
        public readonly bool $is_enabled,
    ) {}
}
