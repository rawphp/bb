<?php

namespace App\Capabilities\Nutrition;

use App\Actions\Recipes\DraftRecipe;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\RecipeImportInput;
use App\Capabilities\Nutrition\Data\RecipeImportOutput;
use App\Capabilities\Support\HostDefinition;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class RecipeImportCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('recipe_import')
            ->description('Read a recipe from a public web page with AI: its name, servings, steps and ingredients, each matched to a database food for the user to confirm. Use it when the user gives a recipe\'s web address; save it with recipe_save once each item has a food and a meal type is picked. It saves nothing.')
            ->cli('recipe', 'import')
            ->input(RecipeImportInput::class)
            ->output(RecipeImportOutput::class)
            ->run(function (RecipeImportInput $input, CapabilityContext $context): RecipeImportOutput {
                /** @var User $user */
                $user = $context->user();

                // Resolved per call: capabilities are built at boot, and the AI key the call checks can change after.
                return RecipeDrafts::of(RecipeImportOutput::class, app(DraftRecipe::class)->import($user, $input->url), null);
            });
    }
}
