<?php

namespace App\Domain\Review;

use App\Domain\Model\MealCheckInState;
use DateTimeImmutable;

final readonly class ReviewMealItem
{
    public function __construct(
        public string $id,
        public DateTimeImmutable $day,
        public string $name,
        public MealCheckInState $checkIn,
    ) {}

    public function isChecked(): bool
    {
        return $this->checkIn->isCheckedIn();
    }
}
