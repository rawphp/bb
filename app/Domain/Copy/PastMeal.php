<?php

namespace App\Domain\Copy;

use DateTimeImmutable;

/** A meal on an earlier day, as MEAL-061 offers it to have again. */
final readonly class PastMeal
{
    /**
     * @param  list<string>  $entryKeys  what it held, one key per food or recipe ("food:<id>", "recipe:<id>")
     * @param  list<string>  $entryNames  the names of its foods and recipe servings, in their order
     */
    public function __construct(
        public string $mealId,
        public DateTimeImmutable $day,
        public string $name,
        public array $entryKeys,
        public array $entryNames,
    ) {}
}
