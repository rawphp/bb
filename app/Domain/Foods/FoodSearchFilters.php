<?php

namespace App\Domain\Foods;

use App\Domain\MealFit\CuratedGroup;
use App\Domain\Model\DietFilter;

/** What a food search is narrowed to (FOOD-023, FOOD-025 to FOOD-027). */
final readonly class FoodSearchFilters
{
    /**
     * @param  FoodKind|null  $kind  one food kind
     * @param  bool  $curated  curated foods only
     * @param  list<DietFilter>  $diets  every one must be true
     */
    public function __construct(
        public ?FoodKind $kind = null,
        public bool $curated = false,
        public ?CuratedGroup $group = null,
        public array $diets = [],
        public int $limit = 25,
    ) {}

    /** Only curated foods match: the curated filter or a curated group. */
    public function curatedOnly(): bool
    {
        return $this->curated || $this->group !== null;
    }
}
