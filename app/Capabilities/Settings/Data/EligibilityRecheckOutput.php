<?php

namespace App\Capabilities\Settings\Data;

use App\Domain\Safety\RecheckOutcomeKind;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** eligibility_recheck's result: the write result (CAP-010), what the re-check decided, and where to go next. */
final class EligibilityRecheckOutput extends CapabilityData
{
    /**
     * @param  list<string>  $invalidates  read keys whose data changed, as WriteResult (CAP-010)
     */
    public function __construct(
        #[Field(enum: [RecheckOutcomeKind::Keep->value, RecheckOutcomeKind::SwitchToTrainingOnly->value, RecheckOutcomeKind::RunNutritionSetup->value, RecheckOutcomeKind::LeaveFatLoss->value])]
        public readonly string $outcome,
        public readonly string $message,
        #[Field(description: 'onboarding: onboarding_show holds the steps this outcome needs', enum: ['done', 'onboarding'])]
        public readonly string $next,
        public readonly array $invalidates,
        public readonly ?string $toast = null,
        public readonly ?string $undo = null,
    ) {}
}
