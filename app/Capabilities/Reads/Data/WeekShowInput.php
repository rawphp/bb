<?php

namespace App\Capabilities\Reads\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A day of the week the strip shows. */
final class WeekShowInput extends CapabilityData
{
    public function __construct(
        #[Field(description: 'Defaults to today.', format: 'date')] public readonly ?string $date = null,
    ) {}
}
