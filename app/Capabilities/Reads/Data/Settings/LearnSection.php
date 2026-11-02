<?php

namespace App\Capabilities\Reads\Data\Settings;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A group of the Learn index. */
final class LearnSection extends CapabilityData
{
    /**
     * @param  list<LearnArticleRow>  $articles
     */
    public function __construct(
        public readonly string $title,
        #[Field(items: LearnArticleRow::class)]
        public readonly array $articles,
    ) {}
}
