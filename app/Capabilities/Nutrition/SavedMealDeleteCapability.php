<?php

namespace App\Capabilities\Nutrition;

use App\Actions\SavedMeals\DeleteSavedMeal;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\SavedMealDeleteInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Models\SavedMeal;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class SavedMealDeleteCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly DeleteSavedMeal $delete) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // AGT-010: it deletes, so the coach needs the user's say-so.
        return HostDefinition::write('saved_meal_delete', affects: SavedMealReads::AFFECTS, destructive: true)
            ->description('Delete one of the user\'s saved meals and its list of foods. Meals it was already loaded into keep their foods. It cannot be undone.')
            ->cli('saved-meal', 'delete')
            ->input(SavedMealDeleteInput::class)
            ->run(function (SavedMealDeleteInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                ($this->delete)($user, $input->saved_meal_id);

                return new WriteResult(invalidates: SavedMealReads::keys());
            });
    }

    public function owned(User $user): array
    {
        return ['saved_meal_id' => SavedMeal::query()->where('user_id', $user->id)];
    }
}
