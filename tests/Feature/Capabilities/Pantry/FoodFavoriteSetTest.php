<?php

use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\ReadKey;
use App\Domain\Pantry\PantryTab;
use App\Models\Food;
use App\Models\FoodFavorite;
use App\Models\User;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Registry\CapabilityRegistry;

// The HTTP seam of food_favorite_set. The write itself is SetFoodFavoriteTest.

function favoriteOver(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/food_favorite_set', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('FOOD-022 CAP-011 food_favorite_set affects every read that shows a star', function () {
    expect(HostDefinition::affects(app(CapabilityRegistry::class)->get('food_favorite_set')))->toBe(['food_search', 'food_barcode_lookup']);
});

it('FOOD-022 refuses food_favorite_set when signed out', function () {
    favoriteOver(null, ['food_id' => (string) Str::ulid(), 'favorite' => true])->assertUnauthorized();
});

it('FOOD-022 answers 404 for another user\'s custom food', function () {
    $theirs = Food::factory()->custom()->create();

    favoriteOver(User::factory()->create(), ['food_id' => $theirs->id, 'favorite' => true])->assertNotFound();
});

it('FOOD-022 answers 422 naming favorite when it is missing', function () {
    favoriteOver(User::factory()->create(), ['food_id' => Food::factory()->create()->id])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'favorite');
});

it('FOOD-022 stars a food, takes the star back, and says which Pantry lists changed', function () {
    $user = User::factory()->create();
    $food = Food::factory()->custom($user)->create();

    favoriteOver($user, ['food_id' => $food->id, 'favorite' => true])
        ->assertOk()
        ->assertJsonPath('data.invalidates', [
            'food_search{}',
            'food_search{"tab":"curated"}',
            'food_search{"tab":"recents"}',
            'food_search{"tab":"favorites"}',
            'food_search{"tab":"custom"}',
        ]);
    expect(FoodFavorite::query()->where('user_id', $user->id)->pluck('food_id')->all())->toBe([$food->id]);

    // The keys the Pantry reads as it opens and on each tab, built the way the read cache builds them (CAP-017).
    $invalidates = favoriteOver($user, ['food_id' => $food->id, 'favorite' => true])->json('data.invalidates');
    expect($invalidates)->toContain(ReadKey::for('food_search'));
    foreach (PantryTab::cases() as $tab) {
        expect($invalidates)->toContain(ReadKey::for('food_search', ['tab' => $tab->value]));
    }

    favoriteOver($user, ['food_id' => $food->id, 'favorite' => false])->assertOk();
    expect(FoodFavorite::query()->where('user_id', $user->id)->count())->toBe(0);
});
