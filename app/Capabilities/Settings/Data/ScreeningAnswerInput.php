<?php

namespace App\Capabilities\Settings\Data;

use App\Capabilities\Support\DomainEnumValues;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Whether one screening item applies. */
final class ScreeningAnswerInput extends CapabilityData
{
    public function __construct(
        #[Field(enum: DomainEnumValues::SCREENING_ITEM)]
        public readonly string $item,
        public readonly bool $applies,
    ) {}
}
