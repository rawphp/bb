<?php

namespace App\Capabilities\Settings\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** diet_filters_set: the filters that are on (SET-006). A food must match every one. */
final class DietFiltersSetInput extends CapabilityData
{
    /**
     * @param  list<DietFilterInput>  $filters
     */
    public function __construct(
        #[Field(items: DietFilterInput::class)]
        public readonly array $filters,
    ) {}
}
