<?php

namespace App\Capabilities\Nutrition;

use App\Actions\Meals\StepMealFood;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\MealFoodStepInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Models\DayTargetMeal;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MealFoodStepCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly StepMealFood $action) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('meal_food_step', affects: MealReads::AFFECTS)
            ->description('Raise or lower one entry of a planned meal by a step: 5 g for a food, half a serving for a recipe, never below 0 g or a tenth of a serving. The meal\'s other unlocked foods and servings are re-fitted around it. Use it for a small nudge; use meal_food_amount_set for an exact amount.')
            ->cli('meal', 'food-step')
            ->input(MealFoodStepInput::class)
            ->run(function (MealFoodStepInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $meal = ($this->action)($user, $input->meal_id, $input->entry_id, $input->direction);

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
