<?php

namespace App\Capabilities\Reads\Data\Settings;

use Rawphp\Capabilities\Support\CapabilityData;

/** One line of copy. */
final class TextLine extends CapabilityData
{
    public function __construct(
        public readonly string $text,
    ) {}
}
