<?php

namespace App\Domain\Phases;

/** PH-045: a suggested maintenance phase. */
final readonly class MaintenanceSuggestion
{
    public function __construct(
        public int $weeks,
        public bool $isResensitization,
    ) {}
}
