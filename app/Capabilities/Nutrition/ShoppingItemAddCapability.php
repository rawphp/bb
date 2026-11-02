<?php

namespace App\Capabilities\Nutrition;

use App\Actions\Shopping\AddShoppingItem;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\ShoppingItemAddInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\UsesClientUlid;
use App\Capabilities\Support\WriteResult;
use App\Models\ManualShoppingItem;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class ShoppingItemAddCapability implements HostCapability
{
    use UsesClientUlid;

    public function __construct(private readonly AddShoppingItem $add) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('shopping_item_add', affects: ['shopping_show'])
            ->description('Add an item that is not a planned food, such as paper towels, to the Other group of the shopping list. It stays on the list for every date range until it is deleted. A new item takes a new id; from and to name the list on screen, so it is refreshed.')
            ->cli('shopping', 'item-add')
            ->input(ShoppingItemAddInput::class)
            ->run(function (ShoppingItemAddInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                ($this->add)($user, $this->ownedOrNew(ManualShoppingItem::class, $input->id, $user), $input->id, $input->text);

                return new WriteResult(invalidates: ShoppingReads::listsOnScreen($user, ShoppingReads::shown($input->from, $input->to)));
            });
    }
}
