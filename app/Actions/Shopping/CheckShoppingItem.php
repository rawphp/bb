<?php

namespace App\Actions\Shopping;

use App\Domain\Shopping\DateRange;
use App\Domain\Shopping\TickedRange;
use App\Models\ManualShoppingItem;
use App\Models\ShoppingTicks;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * SHOP-005, OFF-030: tick a line of the list off, or take the tick back, in the range the list showed.
 * Each range keeps its own ticks, so a stale tick replayed for another range changes only that range.
 * A manual line must be one of the user's items (404 otherwise, CAP-020). The ticks of ranges that have
 * ended go.
 */
final class CheckShoppingItem
{
    /** @return list<DateRange> the ended ranges whose ticks this dropped */
    public function __invoke(User $user, DateRange $range, string $key, bool $ticked): array
    {
        return DB::transaction(function () use ($user, $range, $key, $ticked): array {
            // The user's row first, as every shopping write that changes ticks does: a range with no ticks
            // yet has no row to lock, and two first ticks at once would both insert one.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $manualId = TickedRange::manualId($key);
            if ($manualId !== null) {
                ManualShoppingItem::query()->where('user_id', $user->id)->findOrFail($manualId);
            }

            $today = CarbonImmutable::now()->toDateTimeImmutable();
            $utc = new DateTimeZone('UTC');
            $rows = ShoppingTicks::query()->where('user_id', $user->id)->lockForUpdate()->get();
            $ended = [];
            foreach ($rows as $index => $row) {
                if ($row->ticked()->hasEnded($today, $utc)) {
                    $ended[] = $row->ticked()->range;
                    $row->delete();
                    $rows->forget($index);
                }
            }

            $row = $rows->first(fn (ShoppingTicks $row) => $row->ticked()->isFor($range));
            $next = TickedRange::check($row?->ticked(), $range, $key, $ticked);
            if ($next !== null) {
                ($row ?? new ShoppingTicks(['user_id' => $user->id]))->fill([
                    'starts_on' => $next->range->lower->format('Y-m-d'),
                    'ends_on' => $next->range->upper->format('Y-m-d'),
                    'keys' => $next->keys,
                ])->save();
            }

            return $ended;
        });
    }
}
