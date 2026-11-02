<?php

namespace App\Capabilities\Reads;

use App\Domain\Foods\Barcode;
use App\Domain\Foods\FoodSource;
use App\Domain\Foods\SearchTerms;
use App\Domain\Foods\Speller;
use App\Domain\MealFit\CuratedGroup;
use App\Domain\Model\DietFilter;
use App\Models\Food;
use App\Models\FoodFavorite;
use App\Models\FoodRecent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The food database query behind the Pantry and the scanner: search (FOOD-002, FOOD-023 to FOOD-027,
 * FOOD-029), "Did you mean" (FOOD-024) and barcode lookup (FOOD-041). The read capabilities shape the rows.
 *
 * Search lists the user's own foods (recents, favorites, custom) first, then curated matches, then the
 * rest of the database. Curated foods are a few hundred rows, so they match with LIKE; the rest uses the
 * full-text index on MySQL and LIKE elsewhere.
 */
final class FoodSearch
{
    /**
     * @param  'curated'|'generic'|'packaged'|'restaurant'|null  $kind  curated, or one food kind
     * @param  list<DietFilter>  $diets  every one must be true
     */
    public function __construct(
        private readonly User $user,
        private readonly ?string $kind = null,
        private readonly ?CuratedGroup $group = null,
        private readonly array $diets = [],
        private readonly int $limit = 25,
    ) {}

    /** @return Collection<int, Food> */
    public function search(string $query): Collection
    {
        $terms = SearchTerms::of($query);
        if ($terms === []) {
            return new Collection;
        }
        $phrase = implode(' ', $terms);

        $found = new Collection;
        if ($this->kind !== 'curated' && $this->group === null) {
            $found = $this->mine($terms, $query)?->limit($this->limit)->get() ?? new Collection;
        }

        $found = $found->concat($this->curated($terms, $phrase)->whereNotIn('id', $found->modelKeys())->limit($this->limit - $found->count())->get());
        $remaining = $this->limit - $found->count();
        if ($this->kind === 'curated' || $this->group !== null || $remaining <= 0) {
            return $found->values();
        }

        return $found->concat($this->others($terms, $phrase)->whereNotIn('id', $found->modelKeys())->limit($remaining)->get())->values();
    }

    /** FOOD-024: the corrected query, only when the query finds nothing and the correction does, under the same filters. */
    public function didYouMean(string $query): ?string
    {
        if ($this->search($query)->isNotEmpty()) {
            return null;
        }

        $corrected = Speller::fromNames(Food::query()->whereNull('user_id')->where('curated', true)->pluck('name'))->correct($query);
        if ($corrected === '' || $corrected === implode(' ', SearchTerms::of($query))) {
            return null;
        }

        return $this->search($corrected)->isNotEmpty() ? $corrected : null;
    }

    /** FOOD-041: the food with this barcode: the user's own first, then curated, then Open Food Facts, then USDA. */
    public static function byBarcode(User $user, string $code): ?Food
    {
        $barcode = Barcode::normalize($code);
        if ($barcode === null) {
            return null;
        }

        return Food::query()->visibleTo($user)->where('barcode', $barcode)
            ->orderByRaw('case when user_id is not null then 0 when curated then 1 when source = ? then 2 else 3 end', [FoodSource::OpenFoodFacts->value])
            ->orderBy('id')
            ->with('servings')
            ->first();
    }

    /**
     * FOOD-023, FOOD-060: the user's custom foods, recents and favorites that match, recents first
     * (newest use first), then favorites, then the rest by name. A barcode query matches by code.
     *
     * @param  list<string>  $terms
     * @return Builder<Food>|null
     */
    private function mine(array $terms, string $query): ?Builder
    {
        $userId = $this->user->id;
        // Pluck the user's small, user_id-indexed id sets first, so the LIKE scan is bounded by them, not the whole table.
        $ids = Food::query()->where('user_id', $userId)->pluck('id')
            ->merge(FoodRecent::query()->where('user_id', $userId)->pluck('food_id'))
            ->merge(FoodFavorite::query()->where('user_id', $userId)->pluck('food_id'))
            ->unique()->values();
        if ($ids->isEmpty()) {
            return null;
        }

        // visibleTo: a recent or favorite never reveals another user's custom food (FOOD-060).
        $builder = $this->filtered()->visibleTo($this->user)->whereIn('foods.id', $ids->all());

        $matchTerms = function (Builder $q) use ($terms) {
            foreach (SearchTerms::meaningful($terms) as $term) {
                $q->where(fn (Builder $q) => $q->where('name', 'like', "%{$term}%")->orWhere('brand', 'like', "%{$term}%"));
            }
        };
        $barcode = Barcode::normalize($query);
        $builder->where(fn (Builder $q) => $barcode === null ? $matchTerms($q) : $q->where($matchTerms)->orWhere('barcode', $barcode));

        $usedAt = '(select max(used_at) from food_recents where food_recents.user_id = ? and food_recents.food_id = foods.id)';

        return $builder
            ->orderByRaw("{$usedAt} is null", [$userId])
            ->orderByRaw("{$usedAt} desc", [$userId])
            ->orderByRaw('exists (select 1 from food_favorites where food_favorites.user_id = ? and food_favorites.food_id = foods.id) desc', [$userId])
            ->orderBy('name')->orderBy('id');
    }

    /** @return Builder<Food> */
    private function filtered(): Builder
    {
        $query = Food::query()->with('servings');

        if (in_array($this->kind, ['generic', 'packaged', 'restaurant'], true)) {
            $query->where('kind', $this->kind);
        }
        foreach ($this->diets as $diet) {
            $query->where($diet->value, true);
        }

        return $query;
    }

    /**
     * @param  list<string>  $terms
     * @return Builder<Food>
     */
    private function curated(array $terms, string $phrase): Builder
    {
        $query = $this->filtered()->whereNull('user_id')->where('curated', true);
        if ($this->group !== null) {
            $query->where('curated_group', $this->group->value);
        }
        foreach (SearchTerms::meaningful($terms) as $term) {
            $query->where('name', 'like', "%{$term}%");
        }

        return $query->orderByRaw('name like ? desc', [$phrase.'%'])->orderBy('name');
    }

    /**
     * @param  list<string>  $terms
     * @return Builder<Food>
     */
    private function others(array $terms, string $phrase): Builder
    {
        $query = $this->filtered()->whereNull('user_id')->where('curated', false);

        if ($query->getModel()->getConnection()->getDriverName() === 'mysql') {
            return $this->fullText($query, $terms);
        }

        foreach (SearchTerms::meaningful($terms) as $term) {
            $query->where(fn (Builder $q) => $q->where('name', 'like', "%{$term}%")->orWhere('brand', 'like', "%{$term}%"));
        }

        return $query->orderByRaw('name like ? desc', [$phrase.'%'])->orderByRaw('length(name)')->orderBy('name');
    }

    /**
     * @param  Builder<Food>  $query
     * @param  list<string>  $terms
     * @return Builder<Food>
     */
    private function fullText(Builder $query, array $terms): Builder
    {
        $boolean = SearchTerms::booleanQuery($terms);
        $meaningful = SearchTerms::withoutStopwords($terms);
        $short = array_values(array_filter($meaningful, fn (string $t) => mb_strlen($t) < SearchTerms::MIN_TOKEN));

        if ($boolean !== null) {
            $query->whereRaw('match(name, brand) against (? in boolean mode)', [$boolean]);
            foreach ($short as $term) {
                $query->where(fn (Builder $q) => $q->where('name', 'like', "%{$term}%")->orWhere('brand', 'like', "%{$term}%"));
            }

            // Relevance only: a name tie-break forces a filesort of every match (350-640 ms vs 11-30 ms on 750k rows).
            return $query->orderByRaw('match(name, brand) against (? in boolean mode) desc', [$boolean]);
        }

        // No full-text terms: the first non-stopword (else the first term) is a prefix match the name index can serve.
        $first = $meaningful[0] ?? $terms[0];
        $short = array_values(array_diff($short, [$first]));
        $query->where('name', 'like', $first.'%');
        foreach ($short as $term) {
            $query->where(fn (Builder $q) => $q->where('name', 'like', "%{$term}%")->orWhere('brand', 'like', "%{$term}%"));
        }

        return $query->orderBy('name');
    }
}
