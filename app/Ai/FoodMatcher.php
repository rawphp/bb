<?php

namespace App\Ai;

use App\Actions\Foods\SearchFoods;
use App\Domain\Foods\FoodSearchFilters;
use App\Domain\Model\DietFilter;
use App\Models\User;

/**
 * FOOD-043, FOOD-044, RCP-003, RCP-010: match the ingredients the AI listed to database foods, by
 * the food search's first result for each item's search term (else its name). The search lists the
 * user's own foods first, and never another user's.
 */
final class FoodMatcher
{
    public function __construct(private readonly SearchFoods $search) {}

    /**
     * @param  list<array{name: string, grams: int|float, search?: string|null}>  $items
     * @param  list<DietFilter>  $diets  a match must pass every one
     * @return list<MatchedItem>
     */
    public function match(User $user, array $items, array $diets = []): array
    {
        $filters = new FoodSearchFilters(diets: $diets, limit: 1);

        return array_map(function (array $item) use ($user, $filters): MatchedItem {
            $term = ($item['search'] ?? null) ?: $item['name'];

            return new MatchedItem($item['name'], round((float) $item['grams'], 1), ($this->search)($user, $term, $filters)->first());
        }, $items);
    }
}
