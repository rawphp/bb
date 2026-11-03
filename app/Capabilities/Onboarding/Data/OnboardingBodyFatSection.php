<?php

namespace App\Capabilities\Onboarding\Data;

use App\Capabilities\Support\ExplicitNulls;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Step 5, body fat (ONB-007, TBL-001): the photo buckets for `sex`, leanest
 * first. `sex` is step 2's answer, null until there is one; the buckets are
 * then the female ones, as in rp-clone (`draft.sex ?? .female`).
 */
final class OnboardingBodyFatSection extends CapabilityData
{
    use ExplicitNulls;

    /** @param  list<OnboardingChoice>  $options  values are body-fat buckets */
    public function __construct(
        public readonly string $help,
        #[Field(enum: ['male', 'female'])]
        public readonly ?string $sex,
        #[Field(items: OnboardingChoice::class)]
        public readonly array $options,
    ) {}
}
