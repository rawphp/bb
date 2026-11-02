<?php

namespace App\Domain\Shopping;

/** A store category with its lines. */
final readonly class ListSection
{
    /** @param  list<ListLine>  $lines */
    public function __construct(
        public StoreCategory $category,
        public array $lines,
    ) {}
}
