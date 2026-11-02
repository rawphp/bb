<?php

namespace App\Actions\Shopping;

use App\Domain\Shopping\TickedRange;
use App\Models\ManualShoppingItem;
use App\Models\ShoppingTicks;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** SHOP-005: delete one of the user's manual items, and its tick with it. */
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

            $row = ShoppingTicks::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if ($row !== null) {
                $row->update(['keys' => $row->ticked()->without(TickedRange::manualKey($item->id))->keys]);
            }
        });
    }
}
