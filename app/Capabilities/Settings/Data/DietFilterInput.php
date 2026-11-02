<?php

namespace App\Capabilities\Settings\Data;

use App\Capabilities\Support\DomainValues;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** One diet filter. */
final class DietFilterInput extends CapabilityData
{
    public function __construct(
        #[Field(enum: DomainValues::DIET_FILTERS)]
        public readonly string $filter,
    ) {}
}
