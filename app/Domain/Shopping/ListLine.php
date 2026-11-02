<?php

namespace App\Domain\Shopping;

/** One line of the list: a planned food with its amount, or a manual item with none. */
final readonly class ListLine
{
    public function __construct(
        /** "food:<id>" or "manual:<id>" */
        public string $key,
        public string $name,
        /** "1100 g"; null for a manual item */
        public ?string $amountText,
        public bool $isTicked,
        public bool $isManual,
    ) {}
}
