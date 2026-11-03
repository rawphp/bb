<?php

namespace App\Capabilities\Nutrition;

use App\Actions\Meals\RemoveMealFood;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\MealFoodRemoveInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Models\DayTargetMeal;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MealFoodRemoveCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly RemoveMealFood $action) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // AGT-010: it deletes, so the coach needs the user's say-so.
        return HostDefinition::write('meal_food_remove', affects: MealReads::AFFECTS, destructive: true)
            ->description('Remove one food or recipe serving from a planned meal. Nothing else in the meal changes and nothing is re-fitted; use meal_rebalance after if the user wants the rest re-fitted. It cannot be undone.')
            ->cli('meal', 'food-remove')
            ->input(MealFoodRemoveInput::class)
            ->run(function (MealFoodRemoveInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $meal = ($this->action)($user, $input->meal_id, $input->entry_id);

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
