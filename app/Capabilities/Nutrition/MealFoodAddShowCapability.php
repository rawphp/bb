<?php

namespace App\Capabilities\Nutrition;

use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\MealFoodAddShowInput;
use App\Capabilities\Nutrition\Data\MealFoodAddShowOutput;
use App\Capabilities\Reads\Nutrition\BasketReader;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Models\DayTargetMeal;
use App\Models\Food;
use App\Models\Recipe;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MealFoodAddShowCapability implements HostCapability, OwnsInput
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('meal_food_add_show')
            ->description('Show what meal_food_add would make of these entries before adding them: the amount the meal fit gives each food or recipe, and the meal\'s macro bars, Targets met badge and short-macro hints after it. Use it to check a set of foods fits a meal before adding them; an entry the meal already holds has no amount. It changes nothing.')
            ->cli('meal', 'food-add-show')
            ->input(MealFoodAddShowInput::class)
            ->output(MealFoodAddShowOutput::class)
            ->run(function (MealFoodAddShowInput $input, CapabilityContext $context): MealFoodAddShowOutput {
                /** @var User $user */
                $user = $context->user();

                return BasketReader::show($user, $input->meal_id, $input->entries);
            });
    }

    public function owned(User $user): array
    {
        return [
            'meal_id' => DayTargetMeal::query()->liveOf($user),
            'entries.*.food_id' => Food::query()->visibleTo($user),
            'entries.*.recipe_id' => Recipe::query()->visibleTo($user),
        ];
    }
}
