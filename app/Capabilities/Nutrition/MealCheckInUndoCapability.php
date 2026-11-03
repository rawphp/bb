<?php

namespace App\Capabilities\Nutrition;

use App\Actions\Meals\RemoveMealCheckIn;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\MealCheckInUndoInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Models\DayTargetMeal;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MealCheckInUndoCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly RemoveMealCheckIn $remove) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // UI-010, OFF-030: removing a check-in is queued offline and has Undo.
        return HostDefinition::write('meal_check_in_undo', affects: MealReads::AFFECTS, reversible: true)
            ->description('Remove a meal\'s check-in, so the meal counts as planned and not eaten; its planned foods stay. Use it when the user checked in the wrong meal or has not eaten it yet. A meal that is not checked in is refused.')
            ->cli('meal', 'check-in-undo')
            ->input(MealCheckInUndoInput::class)
            ->run(function (MealCheckInUndoInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();

                return new WriteResult(invalidates: MealReads::keys(($this->remove)($user, $input->meal_id)));
            });
    }

    public function owned(User $user): array
    {
        return ['meal_id' => DayTargetMeal::query()->liveOf($user)];
    }
}
