<?php

namespace Tests\Support\Fixtures\Rail;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A write input with a secret, to prove the audit stores it redacted (CAP-022). */
final class SecretTallyInput extends CapabilityData
{
    public function __construct(
        public readonly int $amount,
        #[Field(sensitive: true)]
        public readonly string $password,
    ) {}
}
