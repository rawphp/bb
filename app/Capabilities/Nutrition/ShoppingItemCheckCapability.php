<?php

namespace App\Capabilities\Nutrition;

use App\Actions\Shopping\CheckShoppingItem;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\ShoppingItemCheckInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\WriteResult;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class ShoppingItemCheckCapability implements HostCapability
{
    public function __construct(private readonly CheckShoppingItem $check) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('shopping_item_check', affects: ['shopping_show'])
            ->description('Tick a line of the shopping list off, or take the tick back, by its key from shopping_show and the dates that list showed. Each date range keeps its own ticks, and the ticks of ranges that have ended are dropped. It changes only the ticks, never the planned meals.')
            ->cli('shopping', 'item-check')
            ->input(ShoppingItemCheckInput::class)
            ->run(function (ShoppingItemCheckInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $range = ShoppingReads::range($input->from, $input->to);
                $ended = ($this->check)($user, $range, $input->key, $input->ticked);

                return new WriteResult(invalidates: ShoppingReads::keys($range, ...$ended));
            });
    }
}
