<?php

namespace App\Capabilities\Settings\Data;

use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\Sex;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** profile_update: the profile form (SET-004). */
final class ProfileUpdateInput extends CapabilityData
{
    public function __construct(
        #[Field(minLength: 1, maxLength: 100)]
        public readonly string $name,
        #[Field(enum: [Sex::Male->value, Sex::Female->value])]
        public readonly string $sex,
        #[Field(format: 'date')]
        public readonly string $birth_date,
        public readonly HeightInput $height,
        #[Field(enum: [BodyFatBucket::Lowest->value, BodyFatBucket::Low->value, BodyFatBucket::High->value, BodyFatBucket::Highest->value])]
        public readonly string $body_fat_bucket,
        public readonly bool $uses_glp1,
        #[Field(enum: [ExperienceLevel::Beginner->value, ExperienceLevel::Intermediate->value, ExperienceLevel::Advanced->value])]
        public readonly string $experience,
    ) {}
}
