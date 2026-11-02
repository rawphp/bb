<?php

namespace App\Capabilities\Settings\Data;

use App\Capabilities\Support\DomainValues;
use App\Capabilities\Support\WriteOutput;
use App\Capabilities\Support\WriteResult;
use Rawphp\Capabilities\Attributes\Field;

/** eligibility_recheck's result: the write result (CAP-010), what the re-check decided, and where to go next. */
final class EligibilityRecheckOutput extends WriteOutput
{
    public function __construct(
        #[Field(enum: DomainValues::RECHECK_OUTCOME_KINDS)]
        public readonly string $outcome,
        public readonly string $message,
        #[Field(description: 'onboarding: onboarding_show holds the steps this outcome needs', enum: ['done', 'onboarding'])]
        public readonly string $next,
        public readonly WriteResult $result,
    ) {}
}
