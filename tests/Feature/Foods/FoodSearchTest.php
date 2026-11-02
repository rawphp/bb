<?php

use App\Capabilities\Reads\FoodSearch;
use App\Domain\MealFit\CuratedGroup;
use App\Domain\Model\DietFilter;
use App\Models\Food;
use App\Models\FoodFavorite;
use App\Models\FoodRecent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** @param  array<string, mixed>  $attributes */
function foodsMake(string $name, array $attributes = []): Food
{
    return Food::factory()->create(['name' => $name] + $attributes);
}

/** @param  array<string, mixed>  $attributes */
function foodsCurated(string $name, string $group = 'carbs_recommended', array $attributes = []): Food
{
    return foodsMake($name, ['source' => 'curated', 'curated' => true, 'curated_group' => $group] + $attributes);
}

/**
 * @param  list<DietFilter>  $diets
 * @return list<string>
 */
function foodsSearch(User $user, string $query, ?string $kind = null, ?CuratedGroup $group = null, array $diets = [], int $limit = 25): array
{
    return (new FoodSearch($user, $kind, $group, $diets, $limit))->search($query)->pluck('name')->all();
}

// InnoDB indexes full-text only on commit, so a search inside the test's transaction would never see the
// rows it just made. These tests commit that transaction, write for real, and remove their rows afterwards.
function foodsDidYouMean(FoodSearch $search, string $query): ?string
{
    return $search->didYouMean($query, $search->search($query));
}

beforeEach(function () {
    DB::commit();
    expect(Food::withTrashed()->count())->toBe(0);
    $this->usersBefore = (int) User::query()->max('id');
    $this->user = User::factory()->create();
});

afterEach(function () {
    DB::table('foods')->delete();
    User::query()->where('id', '>', $this->usersBefore)->delete();
    DB::beginTransaction();
});

it('FOOD-002 lists curated matches before the rest', function () {
    foodsCurated('Chicken breast', 'protein_recommended');
    foodsMake('Chicken noodle soup');
    foodsMake('Breaded chicken nuggets');

    $foods = (new FoodSearch($this->user))->search('chicken');

    expect($foods->pluck('name')->all())->toContain('Chicken breast', 'Chicken noodle soup', 'Breaded chicken nuggets')
        ->and($foods->first()->name)->toBe('Chicken breast')
        ->and($foods->pluck('curated')->all())->toBe([true, false, false]);
});

it('FOOD-023 orders curated matches by name prefix, then name', function () {
    foodsCurated('Wild rice');
    foodsCurated('Rice cakes', 'carbs_sparingly');
    foodsCurated('Brown rice');

    expect(foodsSearch($this->user, 'rice'))->toBe(['Rice cakes', 'Brown rice', 'Wild rice']);
});

it('FOOD-023 matches every term in any order, in name or brand, ignoring case', function () {
    foodsMake('Breast of chicken');
    foodsMake('Chicken thigh');
    foodsMake('Spread', ['brand' => 'Chicken Farm Breast']);

    expect(foodsSearch($this->user, 'BREAST chicken'))->toEqualCanonicalizing(['Breast of chicken', 'Spread']);
});

it('FOOD-029 matches short terms and stopwords in the full database', function () {
    foodsMake('Oat milk');
    foodsMake('Oat bar');
    foodsMake('The pie');

    expect(foodsSearch($this->user, 'oat ba'))->toBe(['Oat bar'])
        ->and(foodsSearch($this->user, 'oa'))->toEqualCanonicalizing(['Oat milk', 'Oat bar'])
        ->and(foodsSearch($this->user, 'the'))->toBe(['The pie'])
        ->and(foodsSearch($this->user, '%%'))->toBe([]);
});

it('FOOD-023 caps results at the limit across curated and the rest', function () {
    foreach (range(1, 5) as $i) {
        foodsCurated("Rice curated {$i}");
        foodsMake("Rice other {$i}");
    }

    expect(foodsSearch($this->user, 'rice'))->toHaveCount(10)
        ->and(foodsSearch($this->user, 'rice', limit: 7))->toHaveCount(7)
        ->and(array_slice(foodsSearch($this->user, 'rice', limit: 7), 0, 5))->each->toStartWith('Rice curated');
});

it('FOOD-025 filters by curated or by kind', function () {
    foodsCurated('Oats curated');
    foodsMake('Oats generic');
    foodsMake('Oats bar', ['kind' => 'packaged']);
    foodsMake('Oats burger', ['kind' => 'restaurant']);

    expect(foodsSearch($this->user, 'oats', 'curated'))->toBe(['Oats curated'])
        ->and(foodsSearch($this->user, 'oats', 'generic'))->toEqualCanonicalizing(['Oats curated', 'Oats generic'])
        ->and(foodsSearch($this->user, 'oats', 'packaged'))->toBe(['Oats bar'])
        ->and(foodsSearch($this->user, 'oats', 'restaurant'))->toBe(['Oats burger']);
});

it('FOOD-026 filters by curated group', function () {
    foodsCurated('Cream veg', 'veggies');
    foodsCurated('Cream fat', 'fat_sparingly');
    foodsMake('Cream other');

    expect(foodsSearch($this->user, 'cream', group: CuratedGroup::FatSparingly))->toBe(['Cream fat']);
});

it('FOOD-027 requires every selected diet filter to match', function () {
    foodsMake('Bar vegan only', ['vegan' => true, 'vegetarian' => true]);
    foodsMake('Bar vegan and gluten free', ['vegan' => true, 'vegetarian' => true, 'gluten_free' => true]);
    foodsMake('Bar gluten free only', ['gluten_free' => true]);
    foodsMake('Bar plain');

    expect(foodsSearch($this->user, 'bar', diets: [DietFilter::Vegan]))->toEqualCanonicalizing(['Bar vegan only', 'Bar vegan and gluten free'])
        ->and(foodsSearch($this->user, 'bar', diets: [DietFilter::Vegan, DietFilter::GlutenFree]))->toBe(['Bar vegan and gluten free'])
        ->and(foodsSearch($this->user, 'bar', diets: [DietFilter::Vegan, DietFilter::GlutenFree, DietFilter::Paleo]))->toBe([]);
});

it('FOOD-027 applies the diet filters to curated foods too', function () {
    foodsCurated('Chicken breaded', 'protein_recommended', ['gluten_free' => false]);
    foodsCurated('Chicken breast', 'protein_recommended', ['gluten_free' => true]);

    expect(foodsSearch($this->user, 'chicken', diets: [DietFilter::GlutenFree]))->toBe(['Chicken breast'])
        ->and(foodsSearch($this->user, 'chicken', 'curated', diets: [DietFilter::GlutenFree]))->toBe(['Chicken breast']);
});

it('FOOD-023 ignores stopwords when matching curated foods', function () {
    foodsCurated('Chicken breast', 'protein_recommended');
    foodsMake('Chicken breast, roasted');

    $names = foodsSearch($this->user, 'the chicken breast');

    expect($names[0])->toBe('Chicken breast')->and($names)->toContain('Chicken breast, roasted');
});

it('FOOD-023 ranks recents, favorites and custom foods ahead of curated foods and the rest', function () {
    foodsMake('Rice other');
    foodsCurated('Rice curated');
    $favorite = foodsMake('Rice favorite');
    $recent = foodsMake('Rice recent');
    $olderRecent = foodsMake('Rice older recent');
    Food::factory()->custom($this->user)->create(['name' => 'Rice custom']);
    FoodFavorite::factory()->create(['user_id' => $this->user->id, 'food_id' => $favorite->id]);
    FoodRecent::factory()->create(['user_id' => $this->user->id, 'food_id' => $olderRecent->id, 'used_at' => now()->subMinutes(5)]);
    FoodRecent::factory()->create(['user_id' => $this->user->id, 'food_id' => $recent->id, 'used_at' => now()]);

    expect(foodsSearch($this->user, 'rice'))
        ->toBe(['Rice recent', 'Rice older recent', 'Rice favorite', 'Rice custom', 'Rice curated', 'Rice other']);
});

it('FOOD-023 does not rank another user\'s recents and favorites', function () {
    $theirs = foodsMake('Rice theirs');
    $mine = foodsMake('Rice mine');
    foodsCurated('Rice curated');
    $other = User::factory()->create();
    FoodRecent::factory()->create(['user_id' => $other->id, 'food_id' => $theirs->id]);
    FoodFavorite::factory()->create(['user_id' => $other->id, 'food_id' => $mine->id]);

    $names = foodsSearch($this->user, 'rice');

    expect($names[0])->toBe('Rice curated')
        ->and(array_slice($names, 1))->toEqualCanonicalizing(['Rice mine', 'Rice theirs']);
});

it('FOOD-023 lists each food once and honours the limit across every tier', function () {
    $favorite = foodsMake('Rice favorite');
    FoodRecent::factory()->create(['user_id' => $this->user->id, 'food_id' => $favorite->id]);
    FoodFavorite::factory()->create(['user_id' => $this->user->id, 'food_id' => $favorite->id]);
    Food::factory()->custom($this->user)->create(['name' => 'Rice custom']);
    foreach (range(1, 4) as $i) {
        foodsMake("Rice other {$i}");
    }

    $names = foodsSearch($this->user, 'rice', limit: 4);

    // The rest tie on full-text relevance, so their order is the index's.
    expect($names)->toHaveCount(4)
        ->and(array_slice($names, 0, 2))->toBe(['Rice favorite', 'Rice custom'])
        ->and(array_slice($names, 2))->each->toStartWith('Rice other');
});

it('FOOD-023 lists a curated food the user favorited once, in the user\'s tier', function () {
    foodsCurated('Oats curated A');
    $favorite = foodsCurated('Oats curated B');
    FoodFavorite::factory()->create(['user_id' => $this->user->id, 'food_id' => $favorite->id]);

    expect(foodsSearch($this->user, 'oats'))->toBe(['Oats curated B', 'Oats curated A']);
});

it('FOOD-060 never shows another user\'s custom foods in search', function () {
    Food::factory()->custom(User::factory()->create())->create(['name' => 'Secret rice bar', 'brand' => 'Hidden', 'barcode' => '12345678905']);
    foodsMake('Rice cakes');

    expect(foodsSearch($this->user, 'rice'))->toBe(['Rice cakes'])
        ->and(foodsSearch($this->user, 'hidden'))->toBe([])
        ->and(foodsSearch($this->user, '12345678905'))->toBe([])
        ->and(foodsSearch($this->user, 'secret', 'curated'))->toBe([]);
});

it('FOOD-060 never shows another user\'s custom food through a recent or favorite that points at it', function () {
    $other = Food::factory()->custom(User::factory()->create())->create(['name' => 'Secret bar', 'barcode' => '12345678905']);
    FoodFavorite::factory()->create(['user_id' => $this->user->id, 'food_id' => $other->id]);
    FoodRecent::factory()->create(['user_id' => $this->user->id, 'food_id' => $other->id]);

    expect(foodsSearch($this->user, 'secret'))->toBe([])
        ->and(foodsSearch($this->user, '12345678905'))->toBe([]);
});

it('FOOD-060 finds the user\'s custom food by name, brand and barcode, and hides it once deleted', function () {
    $food = Food::factory()->custom($this->user)->create(['name' => 'Protein bar', 'brand' => 'Barco', 'barcode' => '12345678905']);

    foreach (['bar', 'barco', '12345678905', '0012345678905'] as $query) {
        expect(foodsSearch($this->user, $query))->toBe(['Protein bar'], $query);
    }

    $food->delete();
    expect(foodsSearch($this->user, 'bar'))->toBe([])
        ->and(foodsSearch($this->user, '12345678905'))->toBe([]);
});

it('FOOD-025 applies the kind and diet filters to the user\'s own foods too', function () {
    Food::factory()->custom($this->user)->create(['name' => 'Bar custom']);
    $favorite = foodsMake('Bar favorite', ['vegan' => true]);
    FoodFavorite::factory()->create(['user_id' => $this->user->id, 'food_id' => $favorite->id]);

    expect(foodsSearch($this->user, 'bar', diets: [DietFilter::Vegan]))->toBe(['Bar favorite'])
        ->and(foodsSearch($this->user, 'bar', 'curated'))->toBe([])
        ->and(foodsSearch($this->user, 'bar', 'generic'))->toBe(['Bar favorite']);
});

it('FOOD-004 loads each food\'s servings with the results', function () {
    $food = foodsCurated('Chicken breast', 'protein_recommended', ['cooked_ratio' => 0.75]);
    $food->servings()->createMany([['label' => 'breast', 'grams' => 174], ['label' => '4 oz', 'grams' => 113]]);

    $found = (new FoodSearch($this->user))->search('chicken breast')->first();

    expect($found->relationLoaded('servings'))->toBeTrue()
        ->and($found->servings->pluck('label')->all())->toBe(['breast', '4 oz'])
        ->and($found->cooked_ratio)->toBe(0.75);
});

it('FOOD-024 suggests a corrected query when nothing matches', function () {
    foodsCurated('Chicken breast', 'protein_recommended');

    expect(foodsDidYouMean(new FoodSearch($this->user), 'chiken brest'))->toBe('chicken breast');
});

it('FOOD-024 gives no suggestion when the query has hits or cannot be corrected', function () {
    foodsCurated('Chicken breast', 'protein_recommended');

    expect(foodsDidYouMean(new FoodSearch($this->user), 'chicken'))->toBeNull()
        ->and(foodsDidYouMean(new FoodSearch($this->user), 'zzzzzz'))->toBeNull()
        ->and(foodsDidYouMean(new FoodSearch($this->user), '%%'))->toBeNull();
});

it('FOOD-024 only suggests a correction that has hits under the same filters', function () {
    foodsCurated('Chicken breast', 'protein_recommended');

    expect(foodsDidYouMean(new FoodSearch($this->user, group: CuratedGroup::Veggies), 'chiken'))->toBeNull()
        ->and(foodsDidYouMean(new FoodSearch($this->user, group: CuratedGroup::ProteinRecommended), 'chiken'))->toBe('chicken');
});

it('FOOD-024 never learns words from another user\'s custom foods', function () {
    Food::factory()->custom(User::factory()->create())->create(['name' => 'Zanzibar bar', 'curated' => true]);

    expect(foodsDidYouMean(new FoodSearch($this->user), 'zanzibr'))->toBeNull();
});
