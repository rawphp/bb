<?php

namespace App\Capabilities\Reads\Data\Settings;

use Rawphp\Capabilities\Support\CapabilityData;

/** A run of text with its inline style. */
final class ArticleSpan extends CapabilityData
{
    public function __construct(
        public readonly string $text,
        public readonly bool $bold,
        public readonly bool $italic,
    ) {}
}
