<?php

use App\Models\Food;
use App\Models\FoodFavorite;
use App\Models\User;
use Rawphp\Capabilities\Schema\JsonSchemaValidator;
use Tests\Support\ScreenFixture;

beforeEach(function () {
    $this->user = User::factory()->create();
});

function lookUpBarcode(string $barcode): array
{
    return test()->actingAs(test()->user)
        ->postJson('/capabilities/food_barcode_lookup', ['barcode' => $barcode])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->json('data');
}

it('FOOD-041 a known barcode returns the product as a Pantry row, ready to add to the meal', function () {
    $bar = Food::factory()->create([
        'name' => 'Protein bar, cookie dough', 'brand' => 'Grenade', 'barcode' => '5000159484695', 'kind' => 'packaged',
        'kcal' => 380, 'protein' => 32, 'fat' => 14.04, 'carbs' => 30, 'source' => 'off',
    ]);

    $data = lookUpBarcode('5000159484695');

    assertMatchesScreenFixture('food_barcode_lookup', $data);
    expect($data)->toBe([
        'barcode' => '5000159484695',
        'status' => 'found',
        'food' => [
            'food_id' => $bar->id,
            'name' => 'Protein bar, cookie dough',
            'detail' => 'Grenade',
            'raw' => ['kcal' => '380 kcal', 'protein' => 'P 32', 'fat' => 'F 14', 'carbs' => 'C 30'],
            'cooked' => null,
            'basis_text' => 'per 100 g',
            'is_curated' => false,
            'is_favorite' => false,
            'is_in_meal' => false,
        ],
        'message' => null,
    ]);
});

it('FOOD-041 a found food that changes weight when cooked carries its cooked values, and a starred one says so', function () {
    $chicken = Food::factory()->create([
        'name' => 'Chicken breast', 'barcode' => '123', 'kcal' => 120, 'protein' => 22.5, 'fat' => 2.6, 'carbs' => 0,
        'cooked_ratio' => 0.75, 'curated' => true, 'curated_group' => 'protein_recommended',
    ]);
    FoodFavorite::factory()->create(['user_id' => $this->user->id, 'food_id' => $chicken->id]);

    $food = lookUpBarcode('123')['food'];

    expect($food['raw'])->toBe(['kcal' => '120 kcal', 'protein' => 'P 22.5', 'fat' => 'F 2.6', 'carbs' => 'C 0'])
        ->and($food['cooked'])->toBe(['kcal' => '160 kcal', 'protein' => 'P 30', 'fat' => 'F 3.5', 'carbs' => 'C 0'])
        ->and($food['is_curated'])->toBeTrue()
        ->and($food['is_favorite'])->toBeTrue();
});

it('FOOD-041 a food another user starred is not starred for the caller', function () {
    $bar = Food::factory()->create(['barcode' => '124']);
    FoodFavorite::factory()->create(['user_id' => User::factory()->create()->id, 'food_id' => $bar->id]);

    expect(lookUpBarcode('124')['food']['is_favorite'])->toBeFalse();
});

it('FOOD-041 an unknown barcode offers the label scan or a custom food with the barcode filled in', function () {
    $data = lookUpBarcode('0123456789012');

    // The not-found state of the same screen: its own fixture, the capability's schema.
    expect(ScreenFixture::differences(ScreenFixture::load('food_barcode_lookup.not-found'), $data))->toBe([])
        ->and((new JsonSchemaValidator)->validate(ScreenFixture::outputSchema('food_barcode_lookup'), $data))->toBe([]);
    expect($data)->toBe([
        'barcode' => '0123456789012',
        'status' => 'not_found',
        'food' => null,
        'message' => 'We couldn\'t find 0123456789012. Scan the label, or create a custom food with this barcode.',
    ]);
});

it('FOOD-060 another user\'s custom food is never found by its barcode', function () {
    Food::factory()->custom(User::factory()->create())->create(['barcode' => '777']);

    expect(lookUpBarcode('777')['status'])->toBe('not_found');
});

it('CAP-020 food_barcode_lookup refuses a signed-out caller and names a barcode that is not digits', function () {
    $this->postJson('/capabilities/food_barcode_lookup', ['barcode' => '123'])->assertUnauthorized();

    $this->actingAs($this->user)
        ->postJson('/capabilities/food_barcode_lookup', ['barcode' => '12ab'])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.violations.0.field', 'barcode');
});
