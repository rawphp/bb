<?php

namespace App\Capabilities\Nutrition;

use App\Actions\Meals\SetMealFoodLock;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\MealFoodLockSetInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Models\DayTargetMeal;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MealFoodLockSetCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly SetMealFoodLock $action) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('meal_food_lock_set', affects: MealReads::AFFECTS)
            ->description('Lock or unlock one entry of a planned meal. A locked food or recipe serving keeps its exact amount through every re-fit of the meal. Only the lock changes; no amount moves until the next change to the meal.')
            ->cli('meal', 'food-lock-set')
            ->input(MealFoodLockSetInput::class)
            ->run(function (MealFoodLockSetInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $meal = ($this->action)($user, $input->meal_id, $input->entry_id, $input->locked);

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
