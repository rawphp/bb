<?php

namespace App\Capabilities\Progress\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** What goes out with the shared progress image (ANA-010). */
final class ProgressShare extends CapabilityData
{
    public function __construct(
        public readonly string $title,
        public readonly string $message,
    ) {}
}
