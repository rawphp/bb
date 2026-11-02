<?php

namespace App\Capabilities\Settings\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** eligibility_recheck's result: the write result (CAP-010), what the re-check decided, and where to go next. */
final class EligibilityRecheckOutput extends CapabilityData
{
    /**
     * @param  list<string>  $invalidates
     */
    public function __construct(
        #[Field(description: 'read keys whose data changed (CAP-010)')]
        public readonly array $invalidates,
        public readonly ?string $toast,
        public readonly ?string $undo,
        #[Field(enum: ['keep', 'switch_to_training_only', 'run_nutrition_setup', 'leave_fat_loss'])]
        public readonly string $outcome,
        public readonly string $message,
        #[Field(description: 'onboarding: onboarding_show holds the steps this outcome needs', enum: ['done', 'onboarding'])]
        public readonly string $next,
    ) {}
}
