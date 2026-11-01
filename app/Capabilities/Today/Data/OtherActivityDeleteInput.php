<?php

namespace App\Capabilities\Today\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** Delete an Other activity and take its energy out of the day (SCH-032). */
final class OtherActivityDeleteInput extends CapabilityData
{
    public function __construct(
        public readonly string $id,
    ) {}
}
