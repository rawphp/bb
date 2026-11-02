<?php

namespace App\Capabilities\Nutrition;

use App\Actions\Shopping\DeleteShoppingItem;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\ShoppingItemDeleteInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Models\ManualShoppingItem;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class ShoppingItemDeleteCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly DeleteShoppingItem $delete) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // AGT-010: it deletes, so the coach needs the user's say-so.
        return HostDefinition::write('shopping_item_delete', affects: ['shopping_show'], destructive: true)
            ->description('Delete an item the user added to the shopping list, and its tick; from and to name the list on screen, so it is refreshed. Planned foods cannot be deleted here: they leave the list when they leave the meals. It cannot be undone.')
            ->cli('shopping', 'item-delete')
            ->input(ShoppingItemDeleteInput::class)
            ->run(function (ShoppingItemDeleteInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                ($this->delete)($user, $input->id);

                return new WriteResult(invalidates: ShoppingReads::listsOnScreen($user, ShoppingReads::shown($input->from, $input->to)));
            });
    }

    public function owned(User $user): array
    {
        return ['id' => ManualShoppingItem::query()->where('user_id', $user->id)];
    }
}
