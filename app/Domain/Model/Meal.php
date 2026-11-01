<?php

namespace App\Domain\Model;

/** A planned meal and its target. */
final readonly class Meal
{
    public function __construct(
        public string $id,
        public string $name,
        public MealTarget $target,
    ) {}
}
