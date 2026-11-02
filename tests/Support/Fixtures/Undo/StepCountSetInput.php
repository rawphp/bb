<?php

namespace Tests\Support\Fixtures\Undo;

use Rawphp\Capabilities\Support\CapabilityData;

final class StepCountSetInput extends CapabilityData
{
    public function __construct(public readonly int $count) {}
}
