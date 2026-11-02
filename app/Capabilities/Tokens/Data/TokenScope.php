<?php

namespace App\Capabilities\Tokens\Data;

use App\Capabilities\Support\SettingsValues;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A scope a new token can have (CAP-021, CAP-023). */
final class TokenScope extends CapabilityData
{
    public function __construct(
        #[Field(enum: SettingsValues::TOKEN_SCOPES)]
        public readonly string $value,
        public readonly string $label,
        public readonly string $description,
    ) {}
}
