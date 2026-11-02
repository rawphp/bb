<?php

namespace App\Capabilities\Nutrition;

use App\Actions\Recipes\DraftRecipe;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\RecipeGenerateInput;
use App\Capabilities\Nutrition\Data\RecipeGenerateOutput;
use App\Capabilities\Support\HostDefinition;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class RecipeGenerateCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('recipe_generate')
            ->description('Draft a recipe with AI from target protein, fat and carbs per serving (and calories if given), a meal type, a complexity, ingredients to use and preferences, under the user\'s diet filters, with each item matched to a database food. Use it when the user asks for a recipe to fit a meal; save it with recipe_save once they confirm the foods. It saves nothing.')
            ->cli('recipe', 'generate')
            ->input(RecipeGenerateInput::class)
            ->output(RecipeGenerateOutput::class)
            ->run(function (RecipeGenerateInput $input, CapabilityContext $context): RecipeGenerateOutput {
                /** @var User $user */
                $user = $context->user();

                // Resolved per call: capabilities are built at boot, and the AI key the call checks can change after.
                $recipe = app(DraftRecipe::class)->generate(
                    $user,
                    protein: $input->protein,
                    fat: $input->fat,
                    carbs: $input->carbs,
                    kcal: $input->kcal,
                    mealType: $input->meal_type,
                    complexity: $input->complexity,
                    ingredients: $input->ingredients,
                    preferences: $input->preferences,
                );

                return RecipeDrafts::of(RecipeGenerateOutput::class, $recipe, $input->meal_type);
            });
    }
}
