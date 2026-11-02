<?php

namespace App\Domain\Shopping;

/** A shopping item the user typed in (SHOP-005). It stays on every range until deleted. */
final readonly class ManualItem
{
    public function __construct(
        public string $id,
        public string $text,
    ) {}
}
