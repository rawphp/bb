<?php

namespace App\Capabilities\Nutrition;

use App\Actions\Meals\SetMealFoodAmount;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\MealFoodAmountSetInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Models\DayTargetMeal;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MealFoodAmountSetCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly SetMealFoodAmount $action) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('meal_food_amount_set', affects: MealReads::AFFECTS)
            ->description('Set one entry of a planned meal to a typed amount: a food in g, oz or its household unit (household), a recipe in servings. The entry keeps that amount and the meal\'s other unlocked foods and servings are re-fitted around it; locked ones do not move. A unit the entry does not have is refused.')
            ->cli('meal', 'food-amount-set')
            ->input(MealFoodAmountSetInput::class)
            ->run(function (MealFoodAmountSetInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $meal = ($this->action)($user, $input->meal_id, $input->entry_id, $input->value, $input->unit);

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
