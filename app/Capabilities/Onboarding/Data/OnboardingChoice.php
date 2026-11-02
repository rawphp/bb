<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One row of a choice list. A write sends `value` back as it came. `detail`
 * is "" when there is none.
 */
final class OnboardingChoice extends CapabilityData
{
    /** @param  list<OnboardingStatus>  $badges  such as "Recommended" */
    public function __construct(
        public readonly string $value,
        public readonly string $title,
        public readonly string $detail,
        public readonly bool $is_selected,
        public readonly bool $is_enabled,
        #[Field(items: OnboardingStatus::class)]
        public readonly array $badges,
    ) {}
}
