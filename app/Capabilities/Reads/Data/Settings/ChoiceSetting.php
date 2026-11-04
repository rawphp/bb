<?php

namespace App\Capabilities\Reads\Data\Settings;

use App\Capabilities\Support\Option;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A setting picked from a list: the current value and every option. */
final class ChoiceSetting extends CapabilityData
{
    /**
     * @param  list<Option>  $choices
     */
    public function __construct(
        public readonly string $value,
        #[Field(items: Option::class)]
        public readonly array $choices,
    ) {}
}
