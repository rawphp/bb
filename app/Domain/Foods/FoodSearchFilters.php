<?php

namespace App\Domain\Foods;

use App\Domain\MealFit\CuratedGroup;
use App\Domain\Model\DietFilter;

/** What a food search is narrowed to (FOOD-023, FOOD-025 to FOOD-027). */
final readonly class FoodSearchFilters
{
    /**
     * @param  FoodSearchSource|null  $source  FOOD-025's source filter
     * @param  list<DietFilter>  $diets  every one must be true
     */
    public function __construct(
        public ?FoodSearchSource $source = null,
        public ?CuratedGroup $group = null,
        public array $diets = [],
        public int $limit = 25,
    ) {}

    /** Only curated foods match: the Curated source or a curated group (FOOD-026). */
    public function curatedOnly(): bool
    {
        return $this->source === FoodSearchSource::Curated || $this->group !== null;
    }

    /** Only the user's own foods match: Recents, Favorites or Custom, with no curated group. */
    public function ownOnly(): bool
    {
        return $this->group === null && $this->source?->ownOnly() === true;
    }
}
