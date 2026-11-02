<?php

namespace App\Capabilities\Reads\Data;

use App\Capabilities\Reads\Data\Settings\ScreeningQuestion;
use App\Capabilities\Reads\Data\Settings\Status;
use App\Capabilities\Reads\Data\Settings\TextLine;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** eligibility_recheck_show: the screening again, prefilled, and the current mode (SAFE-017). */
final class EligibilityRecheckShowOutput extends CapabilityData
{
    /**
     * @param  list<TextLine>  $reasons
     * @param  list<ScreeningQuestion>  $questions
     */
    public function __construct(
        public readonly Status $mode,
        #[Field(description: 'why the user is training only, if they are', items: TextLine::class)]
        public readonly array $reasons,
        #[Field(items: ScreeningQuestion::class)]
        public readonly array $questions,
    ) {}
}
