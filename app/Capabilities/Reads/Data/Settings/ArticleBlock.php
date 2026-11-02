<?php

namespace App\Capabilities\Reads\Data\Settings;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A paragraph, heading or bullet of an article. The first block is a paragraph: the summary. */
final class ArticleBlock extends CapabilityData
{
    /**
     * @param  list<ArticleSpan>  $spans
     */
    public function __construct(
        #[Field(enum: ['paragraph', 'heading', 'bullet'])]
        public readonly string $kind,
        #[Field(items: ArticleSpan::class)]
        public readonly array $spans,
    ) {}
}
