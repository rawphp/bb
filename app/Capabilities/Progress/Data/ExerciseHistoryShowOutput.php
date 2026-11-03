<?php

namespace App\Capabilities\Progress\Data;

use App\Capabilities\Reads\Data\Today\Status;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Up to 20 sessions of one exercise, newest first, the best e1RM of each marked (ANA-009). */
final class ExerciseHistoryShowOutput extends CapabilityData
{
    /** @param  list<ExerciseHistorySession>  $sessions */
    public function __construct(
        public readonly string $title,
        public readonly string $subtitle,
        #[Field(description: 'The pill shown beside each set marked is_best.')]
        public readonly Status $best,
        #[Field(items: ExerciseHistorySession::class)]
        public readonly array $sessions,
        #[Field(description: 'The id to pass as `before` to read older sessions; null when there are none.')]
        public readonly ?string $next_before,
    ) {}
}
