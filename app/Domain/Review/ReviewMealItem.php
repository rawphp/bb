<?php

namespace App\Domain\Review;

use DateTimeImmutable;

final readonly class ReviewMealItem
{
    public function __construct(
        public string $id,
        public DateTimeImmutable $day,
        public string $name,
        public bool $isChecked,
    ) {}
}
