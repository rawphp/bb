<?php

namespace App\Actions\Shopping;

use App\Domain\Shopping\DateRange;
use App\Domain\Shopping\TickedRange;
use App\Models\ManualShoppingItem;
use App\Models\ShoppingTicks;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * SHOP-005, OFF-030: tick a line of the list off, or take the tick back, in the range the list showed.
 * A manual line must be one of the user's items (404 otherwise, CAP-020).
 */
final class CheckShoppingItem
{
    /** @return DateRange|null the range whose ticks this cleared, when the tick was in another one */
    public function __invoke(User $user, DateRange $range, string $key, bool $ticked): ?DateRange
    {
        if (str_starts_with($key, 'manual:')) {
            ManualShoppingItem::query()->where('user_id', $user->id)->findOrFail(substr($key, strlen('manual:')));
        }

        return DB::transaction(function () use ($user, $range, $key, $ticked): ?DateRange {
            $row = ShoppingTicks::query()->where('user_id', $user->id)->lockForUpdate()->first();
            $saved = $row?->ticked();
            $next = TickedRange::check($saved, $range, $key, $ticked);

            ($row ?? new ShoppingTicks(['user_id' => $user->id]))->fill([
                'starts_on' => $next->range->lower->format('Y-m-d'),
                'ends_on' => $next->range->upper->format('Y-m-d'),
                'keys' => $next->keys,
            ])->save();

            return $saved !== null && ! $saved->isFor($range) ? $saved->range : null;
        });
    }
}
