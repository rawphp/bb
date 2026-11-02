<?php

use App\Capabilities\Nutrition\Data\RecipeSaveInput;
use Rawphp\Capabilities\Registry\CapabilityDefinition;
use Rawphp\Capabilities\Schema\IlluminateServerRuleChecker;
use Rawphp\Capabilities\Schema\InputValidator;
use Rawphp\Capabilities\Schema\SchemaValidationException;

// recipe_save's input through the package's own validation, as a registered capability's invoke does. The bounds
// are in NutritionInputRulesTest; this is the rule the schema cannot say.

/** @return array<string, string> field => message, empty when the input passes */
function recipeSaveViolations(array $changes = []): array
{
    $input = [
        'id' => '01JBX0A1B2C3D4E5F6G7H8J9K0', 'name' => 'Chilli', 'servings' => 4, 'meal_type' => 'dinner',
        'ingredients' => [['food_id' => '01JBX0A1B2C3D4E5F6G7H8J9K1', 'grams' => 500, 'is_cooked' => false]],
        ...$changes,
    ];

    try {
        (new InputValidator(serverRules: new IlluminateServerRuleChecker(app('validator'))))
            ->validate(new CapabilityDefinition(name: 'recipe_save', input: RecipeSaveInput::class), $input);
    } catch (SchemaValidationException $e) {
        return array_column($e->violations, 'message', 'field');
    }

    return [];
}

it('RCP-001 a recipe needs a name, so one that is only spaces is refused', function () {
    expect(recipeSaveViolations())->toBe([])
        ->and(recipeSaveViolations(['name' => "  \n "]))->toBe(['name' => 'Give the recipe a name.']);
});
