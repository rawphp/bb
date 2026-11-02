<?php

namespace App\Capabilities\Reads\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** article_show: the article to open. */
final class ArticleShowInput extends CapabilityData
{
    public function __construct(
        #[Field(minLength: 1, maxLength: 64)]
        public readonly string $slug,
    ) {}
}
