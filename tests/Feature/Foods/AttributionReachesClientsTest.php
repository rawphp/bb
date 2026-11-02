<?php

use App\Capabilities\Nutrition\Data\FoodRow;
use App\Capabilities\Reads\Data\SettingsShowOutput;
use App\Domain\Foods\FoodSource;
use App\Models\Food;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityRegistry;

// The ODbL credit must reach the food detail and Settings → About. The capabilities that fill it are not
// registered yet (A_PANTRY: food_search, A_SCAN: food_barcode_lookup, A_SET1: settings_show). Until then each
// test holds the contract to carry the field; the moment a capability registers, the same test runs it over
// HTTP and fails unless the credit comes back.

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->registered = fn (string $name) => app(CapabilityRegistry::class)->has($name);
});

it('FOOD-001 sends the dataset credits in settings_show, for Settings, About', function () {
    if (! ($this->registered)('settings_show')) {
        expect(SettingsShowOutput::jsonSchema()['required'])->toContain('attributions');

        return;
    }

    $this->actingAs($this->user)->postJson('/capabilities/settings_show')
        ->assertOk()
        ->assertJsonPath('data.attributions', FoodSource::attributions());
});

it('FOOD-001 sends an Open Food Facts food\'s ODbL credit in food_barcode_lookup', function () {
    if (! ($this->registered)('food_barcode_lookup')) {
        expect(FoodRow::jsonSchema()['properties'])->toHaveKey('attribution');

        return;
    }

    Food::factory()->create(['name' => 'Hazel spread', 'source' => 'off', 'barcode' => '3017620422003', 'kind' => 'packaged']);

    $this->actingAs($this->user)->postJson('/capabilities/food_barcode_lookup', ['barcode' => '3017620422003'])
        ->assertOk()
        ->assertJsonPath('data.food.attribution', FoodSource::OpenFoodFacts->attribution());
});

it('FOOD-001 sends an Open Food Facts food\'s ODbL credit in every food_search row', function () {
    if (! ($this->registered)('food_search')) {
        expect(FoodRow::jsonSchema()['properties'])->toHaveKey('attribution');

        return;
    }

    Food::factory()->create(['name' => 'Hazel spread', 'source' => 'off', 'kind' => 'packaged']);

    $rows = collect($this->actingAs($this->user)->postJson('/capabilities/food_search', ['query' => 'hazel'])
        ->assertOk()->json('data.sections'))->flatMap(fn (array $section) => $section['rows']);

    expect($rows->pluck('attribution', 'name')->all())->toBe(['Hazel spread' => FoodSource::OpenFoodFacts->attribution()]);
});
