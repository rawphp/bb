<?php

namespace App\Capabilities\Nutrition;

use App\Actions\Meals\RebalanceMeal;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\MealRebalanceInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Models\DayTargetMeal;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MealRebalanceCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly RebalanceMeal $action) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('meal_rebalance', affects: MealReads::AFFECTS)
            ->description('Re-fit every unlocked food and recipe serving of a planned meal to the meal\'s targets. Locked ones keep their exact amounts. Use it after the user changes the meal\'s targets or removes a food and wants the rest to fill the gap.')
            ->cli('meal', 'rebalance')
            ->input(MealRebalanceInput::class)
            ->run(function (MealRebalanceInput $input, CapabilityContext $context): WriteResult {
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
