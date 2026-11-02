<?php

namespace App\Capabilities\Nutrition;

use App\Actions\SavedMeals\RenameSavedMeal;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\SavedMealRenameInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Models\SavedMeal;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class SavedMealRenameCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly RenameSavedMeal $rename) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('saved_meal_rename', affects: SavedMealReads::AFFECTS)
            ->description('Rename one of the user\'s saved meals. The name is trimmed and cannot be blank; the foods stay as they are.')
            ->cli('saved-meal', 'rename')
            ->input(SavedMealRenameInput::class)
            ->run(function (SavedMealRenameInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                ($this->rename)($user, $input->saved_meal_id, $input->name);

                return new WriteResult(invalidates: SavedMealReads::keys());
            });
    }

    public function owned(User $user): array
    {
        return ['saved_meal_id' => SavedMeal::query()->where('user_id', $user->id)];
    }
}
