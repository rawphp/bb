<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A filter value; `value` is what templates_show takes for the filter's key.
 */
final class TemplateFilterOption extends CapabilityData
{
    public function __construct(
        public readonly ?string $value,
        public readonly string $label,
        public readonly bool $selected,
    ) {}
}
