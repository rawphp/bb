<?php

namespace App\Capabilities\Review\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Step 7: next week's days with the answered moves applied, then Save. */
final class ReviewPreview extends CapabilityData
{
    /** @param  list<ReviewPreviewDay>  $days */
    public function __construct(
        #[Field(items: ReviewPreviewDay::class)]
        public readonly array $days,
        public readonly string $hint,
        #[Field(description: 'Why the week or the last move was refused; null when it builds.')]
        public readonly ?string $error,
        public readonly ReviewMoveLimits $move,
    ) {}
}
