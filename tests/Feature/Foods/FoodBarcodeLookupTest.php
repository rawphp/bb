<?php

use App\Actions\Foods\LookUpFoodBarcode;
use App\Models\Food;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

/** @param  array<string, mixed>  $attributes */
function foodsWithBarcode(string $name, string $barcode, array $attributes = []): Food
{
    return Food::factory()->create(['name' => $name, 'barcode' => $barcode, 'kind' => 'packaged'] + $attributes);
}

it('FOOD-041 finds a food by the exact code, with its servings', function () {
    foodsWithBarcode('Good loaf', '3017620422003')->servings()->create(['label' => '1 slice', 'grams' => 43]);

    $food = (new LookUpFoodBarcode)($this->user, '3017620422003');

    expect($food?->name)->toBe('Good loaf')
        ->and($food?->relationLoaded('servings'))->toBeTrue();
});

it('FOOD-041 matches a UPC-12 stored code to an EAN-13 query and back', function () {
    foodsWithBarcode('Good loaf', '12345678905');

    expect((new LookUpFoodBarcode)($this->user, '0012345678905')?->name)->toBe('Good loaf')
        ->and((new LookUpFoodBarcode)($this->user, '012345678905')?->name)->toBe('Good loaf');
});

it('FOOD-041 finds nothing for an unknown code or one that is not digits', function (string $code) {
    foodsWithBarcode('Good loaf', '12345678905');

    expect((new LookUpFoodBarcode)($this->user, $code))->toBeNull();
})->with(['9999999999999', 'abc', '12ab34', '0000']);

it('FOOD-041 prefers the user\'s own food, then curated, then Open Food Facts, then USDA when codes collide', function () {
    foodsWithBarcode('Usda one', '555', ['source' => 'usda']);
    foodsWithBarcode('Off one', '555', ['source' => 'off']);
    expect((new LookUpFoodBarcode)($this->user, '555')?->name)->toBe('Off one');

    foodsWithBarcode('Curated one', '555', ['source' => 'curated', 'curated' => true, 'curated_group' => 'veggies']);
    expect((new LookUpFoodBarcode)($this->user, '555')?->name)->toBe('Curated one');

    Food::factory()->custom($this->user)->create(['name' => 'Mine', 'barcode' => '555']);
    expect((new LookUpFoodBarcode)($this->user, '555')?->name)->toBe('Mine');
});

it('FOOD-041 ranks a curated food by its curated flag, not its source, ahead of Open Food Facts', function () {
    foodsWithBarcode('Off one', '556', ['source' => 'off']);
    foodsWithBarcode('Curated usda one', '556', ['source' => 'usda', 'curated' => true, 'curated_group' => 'veggies']);

    expect((new LookUpFoodBarcode)($this->user, '556')?->name)->toBe('Curated usda one');
});

it('FOOD-060 never finds another user\'s custom food by barcode, nor the user\'s deleted one', function () {
    Food::factory()->custom(User::factory()->create())->create(['name' => 'Theirs', 'barcode' => '777']);
    Food::factory()->custom($this->user)->create(['name' => 'Deleted', 'barcode' => '888'])->delete();

    expect((new LookUpFoodBarcode)($this->user, '777'))->toBeNull()
        ->and((new LookUpFoodBarcode)($this->user, '888'))->toBeNull();
});
