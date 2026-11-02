<?php

use App\Capabilities\Nutrition\Data\CustomFoodSaveInput;
use App\Capabilities\Nutrition\Data\FoodBarcodeLookupInput;
use App\Capabilities\Nutrition\Data\FoodSearchInput;
use App\Capabilities\Nutrition\Data\MealFoodAddInput;
use App\Capabilities\Nutrition\Data\RecipeGenerateInput;
use App\Capabilities\Nutrition\Data\RecipeSaveInput;
use App\Capabilities\Nutrition\Data\RecipesShowInput;
use App\Capabilities\Nutrition\Data\SavedMealSaveInput;
use App\Capabilities\Nutrition\Data\ScanAdjustInput;
use App\Capabilities\Nutrition\Data\ScanLabelInput;
use App\Capabilities\Nutrition\Data\ScanPhotoInput;
use App\Capabilities\Nutrition\Data\ShoppingItemAddInput;
use App\Domain\MealFit\CuratedGroup;
use Rawphp\Capabilities\Registry\CapabilityDefinition;
use Rawphp\Capabilities\Schema\IlluminateServerRuleChecker;
use Rawphp\Capabilities\Schema\InputValidator;
use Rawphp\Capabilities\Schema\SchemaValidationException;

// The nutrition inputs go through the package's own validation: the JSON schema,
// then the server rules, as a registered capability's invoke does. A string rule
// the package counts as portable (regex, max, in) is skipped on the server, so
// each check here is either in the schema or a rule object.

const NUTRITION_ULID = '01JBX0A1B2C3D4E5F6G7H8J9K0';
const NUTRITION_OTHER_ULID = '01JBX0M1N2P3Q4R5S6T7V8W9X0';
const NUTRITION_JPEG = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQ==';

/**
 * @param  class-string  $input
 * @param  array<string, mixed>  $data
 * @return list<string> the fields the validation names, empty when it passes
 */
function nutritionInputViolations(string $input, array $data): array
{
    $validator = new InputValidator(serverRules: new IlluminateServerRuleChecker(app('validator')));

    try {
        $validator->validate(new CapabilityDefinition(name: 'nutrition_input_test', input: $input), $data);
    } catch (SchemaValidationException $e) {
        return array_values(array_unique(array_column($e->violations, 'field')));
    }

    return [];
}

/** @return array<string, array{class-string, array<string, mixed>}> a valid input of each nutrition write or read with a check */
function nutritionValidInputs(): array
{
    $food = ['food_id' => NUTRITION_OTHER_ULID, 'grams' => 120];

    return [
        'custom_food_save' => [CustomFoodSaveInput::class, ['id' => NUTRITION_ULID, 'name' => 'Oat bar', 'serving_size' => 1, 'serving_unit' => 'piece', 'serving_grams' => 40, 'protein' => 4, 'fat' => 5, 'carbs' => 24, 'fiber' => 3, 'barcode' => '00123456789012']],
        'custom_food_save with no barcode' => [CustomFoodSaveInput::class, ['id' => NUTRITION_ULID, 'name' => 'Oat bar', 'serving_size' => 1, 'serving_unit' => 'piece', 'serving_grams' => 40, 'protein' => 4, 'fat' => 5, 'carbs' => 24, 'fiber' => 3, 'barcode' => null]],
        'food_barcode_lookup' => [FoodBarcodeLookupInput::class, ['barcode' => '9300633603263']],
        'scan_label' => [ScanLabelInput::class, ['image' => NUTRITION_JPEG, 'barcode' => '9300633603263']],
        'scan_label with no barcode' => [ScanLabelInput::class, ['image' => NUTRITION_JPEG]],
        'scan_photo' => [ScanPhotoInput::class, ['image' => NUTRITION_JPEG]],
        'scan_adjust' => [ScanAdjustInput::class, ['rows' => [['name' => 'Rice', 'grams' => 150, 'food_id' => NUTRITION_OTHER_ULID]], 'from_portion' => 1, 'portion' => 1.25]],
        'meal_food_add a food' => [MealFoodAddInput::class, ['meal_id' => NUTRITION_OTHER_ULID, 'entries' => [['id' => NUTRITION_ULID, 'food_id' => NUTRITION_OTHER_ULID, 'weighing' => 'cooked']]]],
        'meal_food_add a recipe' => [MealFoodAddInput::class, ['meal_id' => NUTRITION_OTHER_ULID, 'entries' => [['id' => NUTRITION_ULID, 'recipe_id' => NUTRITION_OTHER_ULID, 'food_id' => null]]]],
        'saved_meal_save from a meal' => [SavedMealSaveInput::class, ['id' => NUTRITION_ULID, 'name' => 'Breakfast', 'meal_id' => NUTRITION_OTHER_ULID]],
        'saved_meal_save from a scan' => [SavedMealSaveInput::class, ['id' => NUTRITION_ULID, 'name' => 'Breakfast', 'meal_id' => null, 'foods' => [$food]]],
        'recipe_save' => [RecipeSaveInput::class, ['id' => NUTRITION_ULID, 'name' => 'Chilli', 'servings' => 4, 'meal_type' => 'dinner', 'ingredients' => [[...$food, 'is_cooked' => false]], 'steps' => ['Brown the mince.', 'Simmer for an hour.']]],
        'recipe_generate' => [RecipeGenerateInput::class, ['protein' => 40, 'fat' => 15, 'carbs' => 50, 'meal_type' => 'lunch', 'complexity' => 'simple', 'ingredients' => ['chicken', 'rice']]],
        'food_search' => [FoodSearchInput::class, ['query' => 'oat', 'collection' => 'recipes', 'source' => 'packaged', 'group' => 'fat_sparingly', 'expanded_groups' => array_column(CuratedGroup::cases(), 'value')]],
        'food_search with no filters' => [FoodSearchInput::class, ['collection' => null, 'source' => null, 'group' => null]],
        'recipes_show' => [RecipesShowInput::class, ['meal_type' => 'snack', 'diets' => ['vegan', 'low_fodmap']]],
        'recipes_show with no meal type' => [RecipesShowInput::class, ['meal_type' => null]],
        'shopping_item_add' => [ShoppingItemAddInput::class, ['id' => NUTRITION_ULID, 'text' => 'Paper towels']],
    ];
}

it('CAP-012 accepts a valid input of each nutrition write and read', function (string $input, array $data) {
    expect(nutritionInputViolations($input, $data))->toBe([]);
})->with(nutritionValidInputs());

it('CAP-012 refuses a nutrition input and names the field', function (string $case, array $change, array $remove, string $field) {
    [$input, $data] = nutritionValidInputs()[$case];
    $data = array_diff_key([...$data, ...$change], array_flip($remove));

    expect(nutritionInputViolations($input, $data))->toContain($field);
})->with([
    'a custom food id that is not a ULID' => ['custom_food_save', ['id' => 'food-1'], [], 'id'],
    'a custom food barcode with letters' => ['custom_food_save', ['barcode' => 'abc'], [], 'barcode'],
    'a custom food barcode of 15 digits' => ['custom_food_save', ['barcode' => '123456789012345'], [], 'barcode'],
    'an empty custom food barcode' => ['custom_food_save', ['barcode' => ''], [], 'barcode'],
    'a lookup of an empty barcode' => ['food_barcode_lookup', ['barcode' => ''], [], 'barcode'],
    'a lookup of a barcode with a space' => ['food_barcode_lookup', ['barcode' => '9300 633'], [], 'barcode'],
    'a lookup of a barcode of 5000 digits' => ['food_barcode_lookup', ['barcode' => str_repeat('1', 5000)], [], 'barcode'],
    'a label scan carrying a bad barcode' => ['scan_label', ['barcode' => '12-34'], [], 'barcode'],
    'a label scan that is not a JPEG data URL' => ['scan_label', ['image' => 'https://example.com/label.jpg'], [], 'image'],
    'a plate photo that is a PNG' => ['scan_photo', ['image' => 'data:image/png;base64,iVBORw0KGgo='], [], 'image'],
    'a plate photo over the size bound' => ['scan_photo', ['image' => NUTRITION_JPEG.str_repeat('A', 2_000_000)], [], 'image'],
    'a scan rescaled from portion 0' => ['scan_adjust', ['from_portion' => 0], [], 'from_portion'],
    'a scan rescaled from a negative portion' => ['scan_adjust', ['from_portion' => -1], [], 'from_portion'],
    'a scan rescaled from portion 5' => ['scan_adjust', ['from_portion' => 5], [], 'from_portion'],
    'a meal entry with no food or recipe' => ['meal_food_add a food', ['entries' => [['id' => NUTRITION_ULID]]], [], 'entries.0'],
    'a meal entry with a food and a recipe' => ['meal_food_add a food', ['entries' => [['id' => NUTRITION_ULID, 'food_id' => NUTRITION_OTHER_ULID, 'recipe_id' => NUTRITION_OTHER_ULID]]], [], 'entries.0'],
    'a second meal entry with neither' => ['meal_food_add a food', ['entries' => [['id' => NUTRITION_ULID, 'food_id' => NUTRITION_OTHER_ULID], ['id' => NUTRITION_OTHER_ULID, 'food_id' => null, 'recipe_id' => null]]], [], 'entries.1'],
    'a meal entry id that is not a ULID' => ['meal_food_add a food', ['entries' => [['id' => 'entry-1', 'food_id' => NUTRITION_OTHER_ULID]]], [], 'entries.0.id'],
    'a meal entry weighed fried' => ['meal_food_add a food', ['entries' => [['id' => NUTRITION_ULID, 'food_id' => NUTRITION_OTHER_ULID, 'weighing' => 'fried']]], [], 'entries.0.weighing'],
    'a saved meal from nothing' => ['saved_meal_save from a scan', ['foods' => []], [], 'foods'],
    'a saved meal with no meal and no foods sent' => ['saved_meal_save from a scan', [], ['foods'], 'foods'],
    'a saved meal from a meal and foods' => ['saved_meal_save from a meal', ['foods' => [['food_id' => NUTRITION_OTHER_ULID, 'grams' => 120]]], [], 'meal_id'],
    'a saved meal id that is not a ULID' => ['saved_meal_save from a meal', ['id' => 'saved-1'], [], 'id'],
    'a recipe id that is not a ULID' => ['recipe_save', ['id' => 'chilli'], [], 'id'],
    'a recipe step that is an object' => ['recipe_save', ['steps' => [['x' => 1]]], [], 'steps.0'],
    'a recipe step of 1001 characters' => ['recipe_save', ['steps' => ['Stir.', str_repeat('a', 1001)]], [], 'steps.1'],
    'an empty recipe step' => ['recipe_save', ['steps' => ['']], [], 'steps.0'],
    'a generate ingredient that is a number' => ['recipe_generate', ['ingredients' => [123]], [], 'ingredients.0'],
    'an empty generate ingredient' => ['recipe_generate', ['ingredients' => ['rice', '']], [], 'ingredients.1'],
    'a generate ingredient of 101 characters' => ['recipe_generate', ['ingredients' => ['rice', str_repeat('a', 101)]], [], 'ingredients.1'],
    'a search of an unknown collection' => ['food_search', ['collection' => 'meals'], [], 'collection'],
    'a search of an unknown source' => ['food_search', ['source' => 'restaurant'], [], 'source'],
    'a search of an unknown group' => ['food_search', ['group' => 'fat_before_bed'], [], 'group'],
    'a search opening an unknown group' => ['food_search', ['expanded_groups' => ['veggies', 'fruit']], [], 'expanded_groups.1'],
    'recipes for an unknown meal type' => ['recipes_show', ['meal_type' => 'brunch'], [], 'meal_type'],
    'recipes for an unknown diet' => ['recipes_show', ['diets' => ['vegan', 'keto']], [], 'diets.1'],
    'a shopping item id that is not a ULID' => ['shopping_item_add', ['id' => 'item-1'], [], 'id'],
]);
