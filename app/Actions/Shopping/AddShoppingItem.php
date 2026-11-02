<?php

namespace App\Actions\Shopping;

use App\Models\ManualShoppingItem;
use App\Models\User;

/**
 * SHOP-005: add an item the user typed in, under the ULID the app made for it (CAP-019). Sent again
 * with the same ULID, it keeps the one item.
 */
final class AddShoppingItem
{
    /** @param  ManualShoppingItem|null  $item  the user's item with this ULID; null adds one */
    public function __invoke(User $user, ?ManualShoppingItem $item, string $id, string $text): ManualShoppingItem
    {
        if ($item === null) {
            $item = new ManualShoppingItem;
            $item->id = $id;
            $item->user_id = $user->id;
        }

        $item->text = trim($text);
        $item->save();

        return $item;
    }
}
