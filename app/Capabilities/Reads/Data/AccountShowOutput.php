<?php

namespace App\Capabilities\Reads\Data;

use Rawphp\Capabilities\Support\CapabilityData;

final class AccountShowOutput extends CapabilityData
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
    ) {}
}
