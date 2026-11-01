<?php

namespace Tests\Support\Fixtures\Rail;

use Rawphp\Capabilities\Support\CapabilityData;

final class TallyInput extends CapabilityData
{
    public function __construct(public readonly int $amount) {}
}
