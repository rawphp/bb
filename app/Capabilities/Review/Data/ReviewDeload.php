<?php

namespace App\Capabilities\Review\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** REV-011: how Drop fatigue meets the deload. */
final class ReviewDeload extends CapabilityData
{
    public function __construct(
        #[Field(description: '`deload_now` when the deload is 1 or 2 weeks away; otherwise `ask`.', enum: ['deload_now', 'ask'])]
        public readonly string $kind,
        #[Field(description: '"Make this your deload week too?"; null for deload_now.')]
        public readonly ?string $question,
        #[Field(description: 'The answer that applies: the user\'s, or the default.')]
        public readonly ?bool $answer,
        public readonly ?string $note,
        #[Field(description: '"Target RIR by week: 3, 2, 1, 0" for the shortened mesocycle; null when the deload does not move.')]
        public readonly ?string $rir_label,
    ) {}
}
