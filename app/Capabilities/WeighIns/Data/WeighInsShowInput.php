<?php

namespace App\Capabilities\WeighIns\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

final class WeighInsShowInput extends CapabilityData
{
    public function __construct(
        #[Field(format: 'date', description: 'The next_before of the previous page, to read the 90 days before it. Null for the newest page.')]
        public readonly ?string $before = null,
    ) {}
}
