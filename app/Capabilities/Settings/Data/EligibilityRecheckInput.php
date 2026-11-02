<?php

namespace App\Capabilities\Settings\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** eligibility_recheck: the screening answers (SAFE-017). Measured items are worked out again on the server. */
final class EligibilityRecheckInput extends CapabilityData
{
    /**
     * @param  list<ScreeningAnswerInput>  $answers
     */
    public function __construct(
        #[Field(items: ScreeningAnswerInput::class)]
        public readonly array $answers,
    ) {}
}
