<?php

namespace App\Domain\Review;

use App\Domain\Review\NextWeekCalories\Kind;

/** The calories a review gives next week. */
final readonly class NextWeekCalories
{
    private function __construct(
        public Kind $kind,
        /** Only for Average: the daily average to reach. */
        public ?float $kcal,
    ) {}

    public static function planned(): self
    {
        return new self(Kind::Planned, null);
    }

    public static function average(float $kcal): self
    {
        return new self(Kind::Average, $kcal);
    }

    public static function dietBreak(): self
    {
        return new self(Kind::DietBreak, null);
    }
}
