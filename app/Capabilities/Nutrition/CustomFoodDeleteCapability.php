<?php

namespace App\Capabilities\Nutrition;

use App\Actions\Foods\DeleteCustomFood;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\CustomFoodDeleteInput;
use App\Capabilities\Reads\Pantry\PantryKeys;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\WriteResult;
use App\Models\Food;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class CustomFoodDeleteCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly DeleteCustomFood $delete) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // AGT-010: it deletes, so the coach needs the user's say-so.
        return HostDefinition::write('custom_food_delete', affects: ['custom_foods_show', 'food_search'], destructive: true)
            ->description('Delete one of the user\'s custom foods, so search, barcode lookup and the custom foods list stop showing it. Meals, recipes and saved meals that already use it keep it. It cannot be undone.')
            ->cli('custom-food', 'delete')
            ->input(CustomFoodDeleteInput::class)
            ->run(function (CustomFoodDeleteInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                ($this->delete)($user, $input->food_id);

                return new WriteResult(invalidates: [ReadKey::for('custom_foods_show'), ...PantryKeys::opened()]);
            });
    }

    public function owned(User $user): array
    {
        return ['food_id' => Food::query()->customOf($user)];
    }
}
