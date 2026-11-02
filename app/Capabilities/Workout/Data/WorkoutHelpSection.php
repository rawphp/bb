<?php

namespace App\Capabilities\Workout\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A help text the server owns (WO-007, WO-030, WO-052, FB-006, UI-009).
 * `article` is the article_show key Read more opens; null when none covers it.
 */
final class WorkoutHelpSection extends CapabilityData
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $article,
    ) {}
}
