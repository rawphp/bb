<?php

namespace App\Capabilities\Progress\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A filled bar: its words, "0/18550", and how full it is, worked out on the server. */
final class ProgressBar extends CapabilityData
{
    public function __construct(
        public readonly string $title,
        public readonly string $short_label,
        public readonly string $value_label,
        #[Field(minimum: 0, maximum: 1)]
        public readonly float $fraction,
    ) {}
}
