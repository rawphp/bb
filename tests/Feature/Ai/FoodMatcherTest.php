<?php

use App\Ai\FoodMatcher;
use App\Ai\MatchedItem;
use App\Domain\Model\DietFilter;
use App\Models\Food;
use App\Models\User;

function matcherFood(string $name, array $attrs = []): Food
{
    return Food::factory()->create(['name' => $name, 'curated' => true, ...$attrs]);
}

it('FOOD-043 matches an item to a database food using its search term, grams to one place', function () {
    $user = User::factory()->create();
    $chicken = matcherFood('Chicken breast');

    $matched = app(FoodMatcher::class)->match($user, [['name' => 'Grilled chicken', 'search' => 'chicken breast', 'grams' => 150.04]]);

    expect($matched)->toHaveCount(1)
        ->and($matched[0])->toBeInstanceOf(MatchedItem::class)
        ->and($matched[0]->name)->toBe('Grilled chicken')
        ->and($matched[0]->grams)->toBe(150.0)
        ->and($matched[0]->food?->id)->toBe($chicken->id);
});

it('FOOD-043 falls back to the name when there is no search term', function () {
    $user = User::factory()->create();
    matcherFood('Banana');

    $matched = app(FoodMatcher::class)->match($user, [['name' => 'banana', 'search' => '', 'grams' => 100]]);

    expect($matched[0]->food?->name)->toBe('Banana');
});

it('FOOD-043 leaves an unmatched item without a food', function () {
    $matched = app(FoodMatcher::class)->match(User::factory()->create(), [['name' => 'Mystery', 'search' => 'zzzz', 'grams' => 20]]);

    expect($matched[0]->food)->toBeNull()->and($matched[0]->grams)->toBe(20.0);
});

it('RCP-010 matches only foods that pass the diet filters', function () {
    $user = User::factory()->create();
    matcherFood('Chicken breast', ['vegan' => false]);
    matcherFood('Chicken style tofu', ['vegan' => true]);

    $matched = app(FoodMatcher::class)->match($user, [['name' => 'chicken', 'search' => 'chicken', 'grams' => 100]], [DietFilter::Vegan]);

    expect($matched[0]->food?->name)->toBe('Chicken style tofu');
});

it('FOOD-043 prefers the user\'s own custom food, and never matches another user\'s', function () {
    [$user, $other] = User::factory()->count(2)->create();
    matcherFood('Protein shake');
    $mine = Food::factory()->custom($user)->create(['name' => 'Protein shake (mine)']);
    Food::factory()->custom($other)->create(['name' => 'Protein shake (theirs)']);

    expect(app(FoodMatcher::class)->match($user, [['name' => 'Shake', 'search' => 'protein shake', 'grams' => 300]])[0]->food?->id)->toBe($mine->id)
        ->and(app(FoodMatcher::class)->match(User::factory()->create(), [['name' => 'Shake', 'search' => 'protein shake', 'grams' => 300]])[0]->food?->name)->toBe('Protein shake');
});
