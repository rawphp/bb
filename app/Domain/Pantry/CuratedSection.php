<?php

namespace App\Domain\Pantry;

use App\Domain\MealFit\CuratedGroup;

/** FOOD-021: one curated group on the Curated tab, the foods it shows, and whether "Show 25 more" has more to show. */
final readonly class CuratedSection
{
    /** @param  list<PantryFood>  $foods */
    public function __construct(
        public CuratedGroup $group,
        public array $foods,
        public bool $hasMore,
    ) {}
}
