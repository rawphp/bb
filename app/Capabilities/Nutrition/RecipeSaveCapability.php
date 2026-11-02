<?php

namespace App\Capabilities\Nutrition;

use App\Actions\Recipes\SaveRecipe;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\RecipeIngredientInput;
use App\Capabilities\Nutrition\Data\RecipeSaveInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\UsesClientUlid;
use App\Capabilities\Support\WriteResult;
use App\Models\Food;
use App\Models\Recipe;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class RecipeSaveCapability implements HostCapability, OwnsInput
{
    use UsesClientUlid;

    public function __construct(private readonly SaveRecipe $save) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('recipe_save', affects: ['recipes_show', 'recipe_show', 'today_show'])
            ->description('Create a recipe, or replace one of the user\'s own, from its name, servings, meal type, ingredients (a food and its grams for the whole batch, weighed raw or cooked) and steps. Use it to save a recipe the user built, or a drafted one from recipe_generate or recipe_import once each item has a food. A new recipe takes a new id; an edit also updates every meal it is planned in that is not checked in.')
            ->cli('recipe', 'save')
            ->input(RecipeSaveInput::class)
            ->run(function (RecipeSaveInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();

                ($this->save)(
                    $user,
                    $this->ownedOrNew(Recipe::class, $input->id, $user),
                    $input->id,
                    $input->name,
                    $input->servings,
                    $input->meal_type,
                    array_map(fn (RecipeIngredientInput $ingredient): array => [
                        'food_id' => $ingredient->food_id,
                        'grams' => $ingredient->grams,
                        'is_cooked' => $ingredient->is_cooked,
                    ], $input->ingredients),
                    $input->steps,
                );

                return new WriteResult(invalidates: [...RecipeReads::keys($input->id), ReadKey::for('today_show')]);
            });
    }

    /** A food the user can see: the shared database, or their own, deleted since or not (FOOD-061). */
    public function owned(User $user): array
    {
        return ['ingredients.*.food_id' => Food::withTrashed()->visibleTo($user)];
    }
}
