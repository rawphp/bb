<?php

namespace App\Actions\Foods;

use App\Domain\Foods\Barcode;
use App\Domain\Foods\FoodSearchFilters;
use App\Domain\Foods\FoodSearchSource;
use App\Domain\Foods\SearchTerms;
use App\Domain\Foods\Speller;
use App\Models\Food;
use App\Models\FoodFavorite;
use App\Models\FoodRecent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The food database search behind the Pantry: search (FOOD-002, FOOD-023 to FOOD-027,
 * FOOD-029) and "Did you mean" (FOOD-024). The Action the food_search capability (A_PANTRY) calls from
 * run(); the capability shapes the rows. Barcode lookup is LookUpFoodBarcode.
 *
 * Search lists the user's own foods (recents, favorites, custom) first, then curated matches, then the
 * rest of the database. Curated foods are a few hundred rows, so they match with LIKE; the rest uses the
 * full-text index on MySQL and LIKE elsewhere.
 */
final class SearchFoods
{
    /** @return Collection<int, Food> */
    public function __invoke(User $user, string $query, FoodSearchFilters $filters = new FoodSearchFilters): Collection
    {
        $terms = SearchTerms::of($query);
        if ($terms === []) {
            return new Collection;
        }
        $phrase = implode(' ', $terms);

        $found = new Collection;
        if (! $filters->curatedOnly()) {
            $found = $this->mine($user, $filters, $terms, $query)->limit($filters->limit)->get();
        }
        if ($filters->ownOnly()) {
            return $found->values();
        }

        $found = $found->concat($this->curated($filters, $terms, $phrase)->whereNotIn('id', $found->modelKeys())->limit($filters->limit - $found->count())->get());
        $remaining = $filters->limit - $found->count();
        if ($filters->curatedOnly() || $remaining <= 0) {
            return $found->values();
        }

        return $found->concat($this->others($filters, $terms, $phrase)->whereNotIn('id', $found->modelKeys())->limit($remaining)->get())->values();
    }

    /**
     * FOOD-024: the corrected query, only when the query found nothing and the correction finds something,
     * under the same filters. Takes the caller's result, so the query is not run twice.
     *
     * @param  Collection<int, Food>  $found  what this Action returned for $query
     */
    public function didYouMean(User $user, string $query, Collection $found, FoodSearchFilters $filters = new FoodSearchFilters): ?string
    {
        if ($found->isNotEmpty()) {
            return null;
        }

        $corrected = Speller::fromNames(Food::query()->whereNull('user_id')->where('curated', true)->pluck('name'))->correct($query);
        if ($corrected === '' || $corrected === implode(' ', SearchTerms::of($query))) {
            return null;
        }

        return $this($user, $corrected, $filters)->isNotEmpty() ? $corrected : null;
    }

    /**
     * FOOD-023, FOOD-060: the user's custom foods, recents and favorites that match, recents first
     * (newest use first), then favorites, then the rest by name. A barcode query matches by code.
     *
     * @param  list<string>  $terms
     * @return Builder<Food>
     */
    private function mine(User $user, FoodSearchFilters $filters, array $terms, string $query): Builder
    {
        $userId = $user->id;
        // The user's candidate ids as one derived table: a UNION of three selects that each use their own
        // user_id index, joined to foods by primary key. No id list travels through PHP, and the foods table
        // is read only for those ids, however long the user's history.
        // FOOD-025: Recents, Favorites and Custom each keep only their own select.
        $selects = [
            FoodSearchSource::Custom->value => DB::table('foods')->select('id as food_id')->where('user_id', $userId),
            FoodSearchSource::Recents->value => FoodRecent::query()->toBase()->select('food_id')->where('user_id', $userId),
            FoodSearchSource::Favorites->value => FoodFavorite::query()->toBase()->select('food_id')->where('user_id', $userId),
        ];
        if ($filters->ownOnly()) {
            $selects = [$selects[$filters->source?->value]];
        }
        $candidates = array_shift($selects);
        foreach ($selects as $select) {
            $candidates->union($select);
        }
        $builder = $this->filtered($filters)->select('foods.*')->joinSub($candidates, 'mine', 'mine.food_id', '=', 'foods.id');
        // visibleTo: a recent or favorite never reveals another user's custom food (FOOD-060).
        $builder->visibleTo($user);

        $matchTerms = function (Builder $q) use ($terms) {
            foreach (SearchTerms::meaningful($terms) as $term) {
                $this->nameOrBrandLike($q, $term);
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
    private function filtered(FoodSearchFilters $filters): Builder
    {
        $query = Food::query()->with('servings');

        $kind = $filters->source?->kind();
        if ($kind !== null) {
            $query->where('kind', $kind->value);
        }
        foreach ($filters->diets as $diet) {
            $query->where($diet->value, true);
        }

        return $query;
    }

    /**
     * @param  list<string>  $terms
     * @return Builder<Food>
     */
    private function curated(FoodSearchFilters $filters, array $terms, string $phrase): Builder
    {
        $query = $this->filtered($filters)->whereNull('user_id')->where('curated', true);
        if ($filters->group !== null) {
            $query->where('curated_group', $filters->group->value);
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
    private function others(FoodSearchFilters $filters, array $terms, string $phrase): Builder
    {
        $query = $this->filtered($filters)->whereNull('user_id')->where('curated', false);

        if ($query->getModel()->getConnection()->getDriverName() === 'mysql') {
            return $this->fullText($query, $terms);
        }

        foreach (SearchTerms::meaningful($terms) as $term) {
            $this->nameOrBrandLike($query, $term);
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
                $this->nameOrBrandLike($query, $term);
            }

            // Relevance only: a name tie-break forces a filesort of every match (350-640 ms vs 11-30 ms on 750k rows).
            return $query->orderByRaw('match(name, brand) against (? in boolean mode) desc', [$boolean]);
        }

        // No full-text terms: the first non-stopword (else the first term) is a prefix match the name index can serve.
        $first = $meaningful[0] ?? $terms[0];
        $short = array_values(array_diff($short, [$first]));
        $query->where('name', 'like', $first.'%');
        foreach ($short as $term) {
            $this->nameOrBrandLike($query, $term);
        }

        return $query->orderBy('name');
    }

    /** @param  Builder<Food>  $query */
    private function nameOrBrandLike(Builder $query, string $term): void
    {
        $query->where(fn (Builder $q) => $q->where('name', 'like', "%{$term}%")->orWhere('brand', 'like', "%{$term}%"));
    }
}
