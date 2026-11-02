<?php

namespace App\Capabilities\Nutrition;

use App\Actions\SavedMeals\LoadSavedMeal;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\MealSavedMealLoadInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\WriteResult;
use App\Models\DayTargetMeal;
use App\Models\SavedMeal;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MealSavedMealLoadCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly LoadSavedMeal $load) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('meal_saved_meal_load', affects: ['today_show'])
            ->description('Load one of the user\'s saved meals into a planned meal: its foods join the meal\'s foods, skipping any food the meal already has. Locked foods keep their saved amounts, and every unlocked food and recipe serving in the meal is re-fitted to the meal\'s targets.')
            ->cli('meal', 'saved-meal-load')
            ->input(MealSavedMealLoadInput::class)
            ->run(function (MealSavedMealLoadInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $loaded = ($this->load)($user, $input->meal_id, $input->saved_meal_id);

                if ($loaded['added'] === 0) {
                    return new WriteResult(invalidates: [], toast: "Every food in {$loaded['name']} is already in this meal");
                }

                return new WriteResult(
                    invalidates: [ReadKey::for('today_show'), ReadKey::for('today_show', ['date' => $loaded['date']])],
                    toast: "Loaded {$loaded['name']}",
                );
            });
    }

    public function owned(User $user): array
    {
        return [
            'meal_id' => DayTargetMeal::query()->liveOf($user),
            'saved_meal_id' => SavedMeal::query()->where('user_id', $user->id),
        ];
    }
}
