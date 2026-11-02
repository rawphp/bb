<?php

namespace App\Domain\Onboarding;

/** One day of a week's calorie split on step 13 (ONB-017): "Mon" and its kcal. */
final readonly class CalorieDay
{
    public function __construct(
        public string $name,
        public float $kcal,
    ) {}
}
