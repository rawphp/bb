<?php

use App\Actions\Foods\SaveCustomFood;
use App\Domain\Foods\CustomFoodServing;
use App\Models\Food;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

// The action's job is the foods row it keeps, so it runs on the database.
uses(RefreshDatabase::class);

function barServing(float $grams = 30, float $protein = 6): CustomFoodServing
{
    return new CustomFoodServing(1, 'piece', $grams, $protein, 3, 12, 1);
}

it('FOOD-060 creates the user\'s custom food under the id the client made, per 100 g, with the serving as entered', function () {
    $user = User::factory()->create();
    $id = (string) Str::ulid();

    (new SaveCustomFood)($user, null, $id, 'Protein bar', 'Barco', '12345678905', barServing());

    $food = Food::query()->findOrFail($id);
    expect($food)
        ->user_id->toBe($user->id)
        ->source->toBe('custom')
        ->kind->toBe('custom')
        ->curated->toBeFalse()
        ->name->toBe('Protein bar')
        ->brand->toBe('Barco')
        ->barcode->toBe('12345678905')
        ->kcal->toBe(330.0)
        ->protein->toBe(20.0)
        ->fat->toBe(10.0)
        ->carbs->toBe(40.0)
        ->fiber->toBe(3.33)
        ->custom_serving->toEqual(barServing()->toArray())
        ->and($food->servings()->get(['label', 'grams'])->toArray())->toBe([['label' => '1 piece', 'grams' => 30.0]]);
});

it('FOOD-060 edits the food in place and replaces its serving', function () {
    $user = User::factory()->create();
    $id = (string) Str::ulid();
    $save = new SaveCustomFood;
    $save($user, null, $id, 'Protein bar', null, null, barServing());

    $save($user, Food::query()->findOrFail($id), $id, 'Protein bar XL', null, null, new CustomFoodServing(1.5, 'tbsp', 20.25, 9, 3, 12, 1));

    $food = Food::query()->sole();
    expect($food)
        ->id->toBe($id)
        ->name->toBe('Protein bar XL')
        ->protein->toBe(44.44)
        ->and($food->servings()->get(['label', 'grams'])->toArray())->toBe([['label' => '1.5 tbsp', 'grams' => 20.25]]);
});

it('FOOD-060 keeps every digit the user entered for an edit to open with', function () {
    $user = User::factory()->create();
    $id = (string) Str::ulid();

    (new SaveCustomFood)($user, null, $id, 'Protein bar', null, null, barServing(grams: 33.333, protein: 6.125));

    expect(Food::query()->findOrFail($id)->custom_serving)->toMatchArray(['grams' => 33.333, 'protein' => 6.125]);
});

it('FOOD-060 leaves out a blank brand and barcode', function () {
    $user = User::factory()->create();
    $id = (string) Str::ulid();

    (new SaveCustomFood)($user, null, $id, 'Protein bar', '  ', null, barServing());

    expect(Food::query()->findOrFail($id))->brand->toBeNull()->barcode->toBeNull();
});

it('FOOD-060 stores the barcode as its lookup key, so a scan of the same product finds it', function () {
    $user = User::factory()->create();
    $id = (string) Str::ulid();

    (new SaveCustomFood)($user, null, $id, 'Protein bar', null, '0012345678905', barServing());

    expect(Food::query()->findOrFail($id)->barcode)->toBe('12345678905');
});

it('FOOD-062 saves the name the search handed over, trimmed', function () {
    $user = User::factory()->create();
    $id = (string) Str::ulid();

    (new SaveCustomFood)($user, null, $id, '  oat milk  ', ' Oatly ', null, barServing());

    expect(Food::query()->findOrFail($id))->name->toBe('oat milk')->brand->toBe('Oatly');
});
