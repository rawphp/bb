<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\RecipesShowInput;
use App\Capabilities\Nutrition\Data\RecipesShowOutput;
use App\Capabilities\Reads\Recipes\RecipePayloads;
use App\Capabilities\Support\HostDefinition;
use App\Domain\Recipe\RecipeText;
use App\Models\Recipe;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class RecipesShowCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('recipes_show')
            ->description('List the user\'s own recipes, or the library of ready-made ones, by name, each with its meal type and one serving\'s calories and macros, narrowed to one meal type and to the diet filters given. Use it to find a recipe to open with recipe_show or to plan in a meal. It changes nothing.')
            ->cli('recipe', 'list')
            ->input(RecipesShowInput::class)
            ->output(RecipesShowOutput::class)
            ->run(function (RecipesShowInput $input, CapabilityContext $context): RecipesShowOutput {
                /** @var User $user */
                $user = $context->user();
                $rows = Recipe::query()
                    ->ownedBy($input->segment === 'library' ? null : $user)
                    ->filtered($input->meal_type, $input->diets)
                    ->with('ingredients.food')
                    ->orderBy('name')->orderBy('id')
                    ->get()
                    ->map(fn (Recipe $recipe) => RecipePayloads::row($recipe))
                    ->values()
                    ->all();

                return new RecipesShowOutput(
                    rows: $rows,
                    empty_text: $rows === [] ? RecipeText::emptyText($input->segment, $input->meal_type !== null || $input->diets !== []) : null,
                );
            });
    }
}
