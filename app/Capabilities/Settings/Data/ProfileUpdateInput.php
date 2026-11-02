<?php

namespace App\Capabilities\Settings\Data;

use App\Capabilities\Support\DomainValues;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** profile_update: the profile form (SET-004). */
final class ProfileUpdateInput extends CapabilityData
{
    public function __construct(
        #[Field(minLength: 1, maxLength: 100)]
        public readonly string $name,
        #[Field(enum: DomainValues::SEXES)]
        public readonly string $sex,
        #[Field(format: 'date')]
        public readonly string $birth_date,
        public readonly HeightInput $height,
        #[Field(enum: DomainValues::BODY_FAT_BUCKETS)]
        public readonly string $body_fat_bucket,
        public readonly bool $uses_glp1,
        #[Field(enum: DomainValues::EXPERIENCE_LEVELS)]
        public readonly string $experience,
    ) {}
}
