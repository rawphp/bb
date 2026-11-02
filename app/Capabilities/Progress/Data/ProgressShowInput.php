<?php

namespace App\Capabilities\Progress\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

final class ProgressShowInput extends CapabilityData
{
    public function __construct(
        #[Field(description: 'The chart range: the current phase or the whole plan (ANA-001).', enum: ['phase', 'plan'])]
        public readonly string $scope = 'phase',
    ) {}
}
