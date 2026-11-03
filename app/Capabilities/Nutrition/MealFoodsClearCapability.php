<?php

namespace App\Capabilities\Nutrition;

use App\Actions\Meals\ClearMealFoods;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\MealFoodsClearInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Models\DayTargetMeal;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MealFoodsClearCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly ClearMealFoods $action) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // AGT-010: it deletes, so the coach needs the user's say-so.
        return HostDefinition::write('meal_foods_clear', affects: MealReads::AFFECTS, destructive: true)
            ->description('Remove every food from a planned meal, as the meal menu\'s Remove foods does. Recipe servings stay in the meal, because they are not foods and are removed one by one with meal_food_remove. It cannot be undone.')
            ->cli('meal', 'foods-clear')
            ->input(MealFoodsClearInput::class)
            ->run(function (MealFoodsClearInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $meal = ($this->action)($user, $input->meal_id);

                return new WriteResult(invalidates: MealReads::keys($meal));
            });
    }

    public function owned(User $user): array
    {
        return [
            'meal_id' => DayTargetMeal::query()->liveOf($user),
        ];
    }
}
