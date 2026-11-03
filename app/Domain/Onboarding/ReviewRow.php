<?php

namespace App\Domain\Onboarding;

/** One line of the review (ONB-018): a label and what the user chose, with marks such as "Selected". */
final readonly class ReviewRow
{
    /** @param  list<string>  $badges */
    public function __construct(
        public string $label,
        public string $value,
        public array $badges = [],
    ) {}
}
