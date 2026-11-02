<?php

namespace App\Domain\Placement;

final readonly class PlacedMeal
{
    public function __construct(
        public string $id,
        public ClockTime $time,
        public MealRole $role,
    ) {}
}
