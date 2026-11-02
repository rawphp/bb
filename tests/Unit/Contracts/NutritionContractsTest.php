<?php

use App\Capabilities\Nutrition\Data;
use Rawphp\Capabilities\Schema\JsonSchemaValidator;
use Rawphp\Capabilities\Support\SchemaSnapshot;

// The nutrition contract (MEAL, FOOD, RCP, SHOP): every read and write its views
// use, with the DTOs that own its schema. Writes with no output class return
// WriteResult. The API issue named in nutrition.owners.json registers each one.
$contracts = [
    'nutrition_show' => [Data\NutritionShowInput::class, Data\NutritionShowOutput::class],
    'meal_show' => [Data\MealShowInput::class, Data\MealShowOutput::class],
    'food_search' => [Data\FoodSearchInput::class, Data\FoodSearchOutput::class],
    'food_barcode_lookup' => [Data\FoodBarcodeLookupInput::class, Data\FoodBarcodeLookupOutput::class],
    'saved_meals_show' => [Data\SavedMealsShowInput::class, Data\SavedMealsShowOutput::class],
    'recipes_show' => [Data\RecipesShowInput::class, Data\RecipesShowOutput::class],
    'recipe_show' => [Data\RecipeShowInput::class, Data\RecipeShowOutput::class],
    'shopping_show' => [Data\ShoppingShowInput::class, Data\ShoppingShowOutput::class],
    'custom_foods_show' => [Data\CustomFoodsShowInput::class, Data\CustomFoodsShowOutput::class],
    'meal_food_add' => [Data\MealFoodAddInput::class, null],
    'meal_food_remove' => [Data\MealFoodRemoveInput::class, null],
    'meal_foods_clear' => [Data\MealFoodsClearInput::class, null],
    'meal_food_amount_set' => [Data\MealFoodAmountSetInput::class, null],
    'meal_food_step' => [Data\MealFoodStepInput::class, null],
    'meal_food_lock_set' => [Data\MealFoodLockSetInput::class, null],
    'meal_rebalance' => [Data\MealRebalanceInput::class, null],
    'meal_check_in_undo' => [Data\MealCheckInUndoInput::class, null],
    'meal_planning_done' => [Data\MealPlanningDoneInput::class, null],
    'meal_delete' => [Data\MealDeleteInput::class, null],
    'meal_saved_meal_load' => [Data\MealSavedMealLoadInput::class, null],
    'food_favorite_set' => [Data\FoodFavoriteSetInput::class, null],
    'custom_food_save' => [Data\CustomFoodSaveInput::class, null],
    'custom_food_delete' => [Data\CustomFoodDeleteInput::class, null],
    'scan_photo' => [Data\ScanPhotoInput::class, Data\ScanPhotoOutput::class],
    'scan_describe' => [Data\ScanDescribeInput::class, Data\ScanDescribeOutput::class],
    'scan_adjust' => [Data\ScanAdjustInput::class, Data\ScanAdjustOutput::class],
    'scan_label' => [Data\ScanLabelInput::class, Data\ScanLabelOutput::class],
    'saved_meal_save' => [Data\SavedMealSaveInput::class, null],
    'saved_meal_rename' => [Data\SavedMealRenameInput::class, null],
    'saved_meal_delete' => [Data\SavedMealDeleteInput::class, null],
    'recipe_save' => [Data\RecipeSaveInput::class, null],
    'recipe_delete' => [Data\RecipeDeleteInput::class, null],
    'recipe_generate' => [Data\RecipeGenerateInput::class, Data\RecipeGenerateOutput::class],
    'recipe_import' => [Data\RecipeImportInput::class, Data\RecipeImportOutput::class],
    'shopping_item_check' => [Data\ShoppingItemCheckInput::class, null],
    'shopping_item_add' => [Data\ShoppingItemAddInput::class, null],
    'shopping_item_delete' => [Data\ShoppingItemDeleteInput::class, null],
];

/** @return array<string, mixed> */
function nutritionJson(string $path): array
{
    return json_decode((string) file_get_contents(base_path($path)), true, flags: JSON_THROW_ON_ERROR);
}

it('CAP-032 keeps each nutrition schema snapshot equal to its DTO', function () use ($contracts) {
    foreach ($contracts as $name => [$input, $output]) {
        foreach (['input' => $input, 'output' => $output] as $side => $class) {
            $path = "tests/fixtures/schemas/{$name}.{$side}.json";

            if ($class === null) {
                expect(file_exists(base_path($path)))->toBeFalse("{$path} has no DTO; the write returns WriteResult");

                continue;
            }

            expect(file_exists(base_path($path)))->toBeTrue("{$path} is missing");
            expect(SchemaSnapshot::schemasEqual(nutritionJson($path), $class::jsonSchema()))
                ->toBeTrue("{$path} differs from {$class}::jsonSchema()");
        }
    }
});

it('CAP-002 gives each nutrition read a screen fixture for every state that matches its output DTO', function () use ($contracts) {
    $validator = new JsonSchemaValidator;

    foreach ($contracts as $name => [, $output]) {
        if (! str_ends_with($name, '_show') && $name !== 'food_search' && $name !== 'food_barcode_lookup') {
            continue;
        }

        $states = glob(base_path("tests/fixtures/screens/{$name}{,.*}.json"), GLOB_BRACE) ?: [];
        expect($states)->toContain(base_path("tests/fixtures/screens/{$name}.json"));

        foreach ($states as $path) {
            $fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $file = basename($path);

            expect($validator->validate($output::jsonSchema(), $fixture))->toBe([], "{$file} breaks the {$output} schema");
            // Every key of the DTO is in the fixture, nullable ones too.
            expect($output::fromArray($fixture)->toArray())->toEqual($fixture, $file);
        }
    }
});

it('maps every nutrition capability to exactly one owning API issue', function () use ($contracts) {
    $owners = nutritionJson('tests/fixtures/schemas/nutrition.owners.json');

    expect(array_keys($owners))->toEqualCanonicalizing(array_keys($contracts));
    foreach ($owners as $name => $issue) {
        expect($issue)->toBeInt("{$name} has no issue number");
    }
});
