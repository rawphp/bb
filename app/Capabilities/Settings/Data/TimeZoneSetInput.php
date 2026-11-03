<?php

namespace App\Capabilities\Settings\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** time_zone_set: the device's IANA time zone (SET-012). */
final class TimeZoneSetInput extends CapabilityData
{
    public function __construct(
        public readonly string $time_zone,
    ) {}
}
