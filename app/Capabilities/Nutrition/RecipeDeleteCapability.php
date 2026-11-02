<?php

namespace App\Capabilities\Nutrition;

use App\Actions\Recipes\DeleteRecipe;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\RecipeDeleteInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Models\Recipe;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class RecipeDeleteCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly DeleteRecipe $delete) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // AGT-010: it deletes, so the coach needs the user's say-so.
        return HostDefinition::write('recipe_delete', affects: ['recipes_show', 'recipe_show'], destructive: true)
            ->description('Delete one of the user\'s own recipes, so the recipe lists stop showing it. Use it only when the user asks to delete that recipe; meals it is already planned in keep their copy. Library recipes cannot be deleted, and this cannot be undone.')
            ->cli('recipe', 'delete')
            ->input(RecipeDeleteInput::class)
            ->run(function (RecipeDeleteInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                ($this->delete)($user, $input->recipe_id);

                return new WriteResult(invalidates: RecipeReads::keys($input->recipe_id));
            });
    }

    public function owned(User $user): array
    {
        return ['recipe_id' => Recipe::query()->ownedBy($user)];
    }
}
