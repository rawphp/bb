<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\RecipeShowInput;
use App\Capabilities\Nutrition\Data\RecipeShowOutput;
use App\Capabilities\Reads\Recipes\RecipePayloads;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Models\Recipe;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class RecipeShowCapability implements HostCapability, OwnsInput
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('recipe_show')
            ->description('Show one recipe: its servings, one serving\'s calories and macros, each ingredient with its amount weighed raw or cooked, and its steps. A library recipe is read-only; the user\'s own can be edited with recipe_save or deleted with recipe_delete. It changes nothing.')
            ->cli('recipe', 'show')
            ->input(RecipeShowInput::class)
            ->output(RecipeShowOutput::class)
            ->run(function (RecipeShowInput $input, CapabilityContext $context): RecipeShowOutput {
                /** @var User $user */
                $user = $context->user();

                return RecipePayloads::show(Recipe::query()->visibleTo($user)->with('ingredients.food')->findOrFail($input->recipe_id));
            });
    }

    public function owned(User $user): array
    {
        return ['recipe_id' => Recipe::query()->visibleTo($user)];
    }
}
