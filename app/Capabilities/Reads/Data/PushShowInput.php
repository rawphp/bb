<?php

namespace App\Capabilities\Reads\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

final class PushShowInput extends CapabilityData
{
    public function __construct(
        #[Field(description: 'This browser\'s push service URL, to learn whether it is subscribed for the user.', maxLength: 1024)]
        public readonly ?string $endpoint = null,
    ) {}
}
