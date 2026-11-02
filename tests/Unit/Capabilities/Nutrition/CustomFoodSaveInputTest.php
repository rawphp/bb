<?php

use App\Capabilities\Nutrition\Data\CustomFoodSaveInput;
use Rawphp\Capabilities\Registry\CapabilityDefinition;
use Rawphp\Capabilities\Schema\IlluminateServerRuleChecker;
use Rawphp\Capabilities\Schema\InputValidator;
use Rawphp\Capabilities\Schema\SchemaValidationException;

// custom_food_save's input through the package's own validation: the JSON schema, then the
// server rules, as a registered capability's invoke does (rp-clone's NutritionRules::customFood).

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, string> field => message, empty when the input passes
 */
function customFoodViolations(array $changes = []): array
{
    $input = [
        'id' => '01JBX0A1B2C3D4E5F6G7H8J9K0', 'name' => 'Protein bar', 'brand' => 'Barco', 'barcode' => '12345678905',
        'serving_size' => 1, 'serving_unit' => 'piece', 'serving_grams' => 30,
        'protein' => 6, 'fat' => 3, 'carbs' => 12, 'fiber' => 1,
        ...$changes,
    ];
    $validator = new InputValidator(serverRules: new IlluminateServerRuleChecker(app('validator')));

    try {
        $validator->validate(new CapabilityDefinition(name: 'custom_food_save', input: CustomFoodSaveInput::class), array_filter($input, fn ($value) => $value !== '(missing)'));
    } catch (SchemaValidationException $e) {
        return array_column($e->violations, 'message', 'field');
    }

    return [];
}

it('FOOD-060 takes a custom food with every field', function () {
    expect(customFoodViolations())->toBe([]);
});

it('FOOD-060 takes a food without a brand or barcode', function () {
    expect(customFoodViolations(['brand' => null, 'barcode' => null]))->toBe([])
        ->and(customFoodViolations(['brand' => '(missing)', 'barcode' => '(missing)']))->toBe([]);
});

it('FOOD-060 takes every serving unit', function (string $unit) {
    expect(customFoodViolations(['serving_unit' => $unit]))->toBe([]);
})->with(['g', 'cup', 'tbsp', 'tsp', 'piece']);

it('FOOD-060 refuses a value outside its range', function (string $field, float $value) {
    expect(customFoodViolations([$field => $value]))->toHaveKey($field);
})->with([
    'a serving of 0' => ['serving_size', 0],
    'a serving of 100000' => ['serving_size', 100000],
    'a serving weighing 0 g' => ['serving_grams', 0],
    'a serving weighing 10001 g' => ['serving_grams', 10001],
    'negative protein' => ['protein', -1],
    'negative fat' => ['fat', -1],
    'negative carbs' => ['carbs', -1],
    'negative fiber' => ['fiber', -1],
]);

it('FOOD-060 takes the ends of each range', function (array $changes) {
    expect(customFoodViolations($changes))->toBe([]);
})->with([
    'a serving of 99999' => [['serving_size' => 99999]],
    'a serving weighing 0.01 g' => [['serving_grams' => 0.01, 'protein' => 0, 'fat' => 0, 'carbs' => 0.01, 'fiber' => 0]],
    'a serving weighing 10000 g' => [['serving_grams' => 10000]],
    'no protein' => [['protein' => 0]],
]);

it('FOOD-060 refuses a macro that weighs more than the serving', function (string $field, string $label) {
    expect(customFoodViolations([$field => 30.5]))->toBe([$field => "{$label} can't weigh more than the serving."]);
})->with([
    ['protein', 'Protein'],
    ['fat', 'Fat'],
    ['carbs', 'Carbs'],
    ['fiber', 'Fiber'],
]);

it('FOOD-060 takes a macro that weighs the whole serving', function () {
    expect(customFoodViolations(['protein' => 30, 'fat' => 0, 'carbs' => 0, 'fiber' => 0]))->toBe([]);
});

it('FOOD-060 refuses a name that is only spaces', function () {
    expect(customFoodViolations(['name' => '   ']))->toBe(['name' => 'Enter a name.']);
});

it('FOOD-060 refuses a name or brand over 255 characters', function () {
    expect(customFoodViolations(['name' => str_repeat('a', 256)]))->toHaveKey('name')
        ->and(customFoodViolations(['brand' => str_repeat('a', 256)]))->toHaveKey('brand')
        ->and(customFoodViolations(['name' => str_repeat('a', 255), 'brand' => str_repeat('a', 255)]))->toBe([]);
});

it('FOOD-060 takes a barcode of 1 to 14 digits', function (string $barcode) {
    expect(customFoodViolations(['barcode' => $barcode]))->toBe([]);
})->with(['1', '12345678905', '12345678901234', '00123456789012']);

it('FOOD-060 refuses a barcode that is not 1 to 14 digits', function (string $barcode) {
    expect(customFoodViolations(['barcode' => $barcode]))->toBe(['barcode' => 'A barcode is 1 to 14 digits.']);
})->with(['12ab', '0000', '1234 5678']);
