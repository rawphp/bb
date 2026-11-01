<?php

namespace App\Capabilities\Reads\Data\Settings;

use Rawphp\Capabilities\Support\CapabilityData;

/** An article in the Learn index. */
final class LearnArticleRow extends CapabilityData
{
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly string $summary,
    ) {}
}
