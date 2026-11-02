<?php

namespace App\Capabilities\Reads\Data\Settings;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The profile (SET-004) as the Profile row and the profile form show it. */
final class Profile extends CapabilityData
{
    public function __construct(
        public readonly string $name,
        public readonly ChoiceSetting $sex,
        #[Field(format: 'date')]
        public readonly string $birth_date,
        public readonly string $birth_date_text,
        public readonly Height $height,
        public readonly BodyFat $body_fat,
        public readonly bool $uses_glp1,
        public readonly ChoiceSetting $experience,
    ) {}
}
