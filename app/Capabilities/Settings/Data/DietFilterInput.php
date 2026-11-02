<?php

namespace App\Capabilities\Settings\Data;

use App\Capabilities\Support\DomainEnumValues;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** One diet filter. */
final class DietFilterInput extends CapabilityData
{
    public function __construct(
        #[Field(enum: DomainEnumValues::DIET_FILTER)]
        public readonly string $filter,
    ) {}
}
