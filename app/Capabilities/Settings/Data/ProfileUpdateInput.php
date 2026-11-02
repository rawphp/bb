<?php

namespace App\Capabilities\Settings\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** profile_update: the profile form (SET-004). */
final class ProfileUpdateInput extends CapabilityData
{
    public function __construct(
        #[Field(minLength: 1, maxLength: 100)]
        public readonly string $name,
        #[Field(enum: ['male', 'female'])]
        public readonly string $sex,
        #[Field(format: 'date')]
        public readonly string $birth_date,
        public readonly HeightInput $height,
        #[Field(enum: ['lowest', 'low', 'high', 'highest'])]
        public readonly string $body_fat_bucket,
        public readonly bool $uses_glp1,
        #[Field(enum: ['beginner', 'intermediate', 'advanced'])]
        public readonly string $experience,
    ) {}
}
