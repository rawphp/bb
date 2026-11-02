<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One filter chip (MESO-011): "Days: All", its options and whether it narrows the list.
 */
final class TemplateFilter extends CapabilityData
{
    /**
     * @param  list<TemplateFilterOption>  $options
     */
    public function __construct(
        #[Field(enum: ['days', 'sex', 'focus', 'phase'])]
        public readonly string $key,
        public readonly string $label,
        public readonly bool $active,
        #[Field(items: TemplateFilterOption::class)]
        public readonly array $options,
    ) {}
}
