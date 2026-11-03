<?php

namespace App\Capabilities\Nutrition;

use App\Actions\Meals\AddMealFoods;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\MealFoodAddInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Models\DayTargetMeal;
use App\Models\Food;
use App\Models\Recipe;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MealFoodAddCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly AddMealFoods $action) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('meal_food_add', affects: MealReads::AFFECTS)
            ->description('Add foods and recipes to a planned meal when the user picks something to eat: each entry names a food or a recipe, and the whole meal is re-fitted to the meal\'s targets, so unlocked foods and servings change amounts. A food or recipe already in the meal is skipped; an entry with grams (a scanned food) joins locked at those grams, and a recipe joins as servings.')
            ->cli('meal', 'food-add')
            ->input(MealFoodAddInput::class)
            ->run(function (MealFoodAddInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $meal = ($this->action)($user, $input->meal_id, $input->entries);

                return new WriteResult(invalidates: MealReads::keys($meal));
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
