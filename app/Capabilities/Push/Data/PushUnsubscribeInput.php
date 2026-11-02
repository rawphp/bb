<?php

namespace App\Capabilities\Push\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

final class PushUnsubscribeInput extends CapabilityData
{
    public function __construct(
        #[Field(description: 'The push service URL of the browser to stop sending to.', maxLength: 1024)]
        public readonly string $endpoint,
    ) {}
}
