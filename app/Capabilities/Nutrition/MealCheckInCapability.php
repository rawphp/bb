<?php

namespace App\Capabilities\Nutrition;

use App\Actions\Meals\CheckInMeal;
use App\Capabilities\HostCapability;
use App\Capabilities\Meal\Data\MealCheckInInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Models\DayTargetMeal;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MealCheckInCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly CheckInMeal $checkIn) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // UI-010, OFF-030: the check-in is queued offline and has Undo.
        return HostDefinition::write('meal_check_in', affects: MealReads::AFFECTS, reversible: true)
            ->description('Check in a planned meal as eaten. With no amounts it records what the meal\'s planned foods and recipe servings add up to, or the meal\'s targets when it has none; a meal with the "Adjust foods" badge is refused until its foods are re-fitted or it is marked done planning. With calories, protein, fat and carbs it records those amounts as a manual check-in. Time is 24-hour "HH:MM", defaulting to when the user acted. It replaces a check-in already there.')
            ->cli('meal', 'check-in')
            ->input(MealCheckInInput::class)
            ->run(function (MealCheckInInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();

                return new WriteResult(invalidates: MealReads::keys(($this->checkIn)($user, $input)));
            });
    }

    public function owned(User $user): array
    {
        return ['meal_id' => DayTargetMeal::query()->liveOf($user)];
    }
}
