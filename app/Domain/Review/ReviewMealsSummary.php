<?php

namespace App\Domain\Review;

/** REV-003 step 2: how many meals were checked in, and the ones that were not. */
final readonly class ReviewMealsSummary
{
    public const EXCELLENT_MESSAGE = 'Excellent work';

    /** @param  list<ReviewMealItem>  $meals */
    public function __construct(public array $meals) {}

    public function total(): int
    {
        return count($this->meals);
    }

    public function checkedIn(): int
    {
        return $this->total() - count($this->unchecked());
    }

    /** @return list<ReviewMealItem> */
    public function unchecked(): array
    {
        return array_values(array_filter($this->meals, fn (ReviewMealItem $meal) => ! $meal->isChecked));
    }

    /** "Excellent work" when every meal was checked in. */
    public function isExcellent(): bool
    {
        return $this->total() > 0 && $this->unchecked() === [];
    }

    public function message(): string
    {
        if ($this->total() === 0) {
            return 'No meals were planned this week';
        }
        if ($this->isExcellent()) {
            return self::EXCELLENT_MESSAGE;
        }

        return "{$this->checkedIn()} of {$this->total()} meals checked in";
    }
}
