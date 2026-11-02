<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\SavedMealRow;
use App\Capabilities\Nutrition\Data\SavedMealsShowInput;
use App\Capabilities\Nutrition\Data\SavedMealsShowOutput;
use App\Capabilities\Support\HostDefinition;
use App\Domain\SavedMeals\SavedMeals;
use App\Models\SavedMeal;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class SavedMealsShowCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('saved_meals_show')
            ->description('List the user\'s saved meals by name, each with how many foods it holds. Use it to find a saved meal to load into a meal, rename or delete. It changes nothing.')
            ->cli('saved-meal', 'list')
            ->input(SavedMealsShowInput::class)
            ->output(SavedMealsShowOutput::class)
            ->run(function (SavedMealsShowInput $input, CapabilityContext $context): SavedMealsShowOutput {
                /** @var User $user */
                $user = $context->user();
                $meals = SavedMeal::query()->where('user_id', $user->id)->withCount('foods')->orderBy('name')->orderBy('id')->get()
                    ->map(fn (SavedMeal $meal): SavedMealRow => new SavedMealRow(
                        saved_meal_id: (string) $meal->id,
                        name: $meal->name,
                        detail: SavedMeals::detail((int) $meal->getAttribute('foods_count')),
                    ))
                    ->values()
                    ->all();

                return new SavedMealsShowOutput(
                    meals: $meals,
                    empty_text: $meals === [] ? 'No saved meals yet. Save one from a meal\'s menu.' : null,
                );
            });
    }
}
