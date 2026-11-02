<?php

namespace App\Capabilities\Reads\Data;

use App\Capabilities\Reads\Data\Settings\ArticleBlock;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** article_show: one Learn article, in blocks (LRN-001). */
final class ArticleShowOutput extends CapabilityData
{
    /**
     * @param  list<ArticleBlock>  $blocks
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        #[Field(items: ArticleBlock::class)]
        public readonly array $blocks,
    ) {}
}
