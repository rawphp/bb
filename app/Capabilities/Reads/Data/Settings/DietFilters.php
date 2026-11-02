<?php

namespace App\Capabilities\Reads\Data\Settings;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Diet filters (SET-006): the row's summary and every filter. */
final class DietFilters extends CapabilityData
{
    /**
     * @param  list<SelectableChoice>  $choices
     */
    public function __construct(
        #[Field(description: '"None", one filter\'s name, or "3 on"')]
        public readonly string $summary,
        #[Field(items: SelectableChoice::class)]
        public readonly array $choices,
    ) {}
}
