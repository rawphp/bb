<?php

namespace App\Actions\Shopping;

use App\Domain\Shopping\TickedRange;
use App\Models\ManualShoppingItem;
use App\Models\ShoppingTicks;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** SHOP-005: delete one of the user's manual items, and its tick in every range with it. */
final class DeleteShoppingItem
{
    public function __invoke(User $user, string $id): void
    {
        DB::transaction(function () use ($user, $id): void {
            // The user's row first, as CheckShoppingItem takes it, so a tick at the same time cannot write
            // the deleted item's tick back.
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $item = ManualShoppingItem::query()->where('user_id', $user->id)->findOrFail($id);
            $item->delete();

            $key = TickedRange::manualKey($item->id);
            foreach (ShoppingTicks::query()->where('user_id', $user->id)->lockForUpdate()->get() as $row) {
                if (in_array($key, $row->keys, true)) {
                    $row->update(['keys' => $row->ticked()->without($key)->keys]);
                }
            }
        });
    }
}
