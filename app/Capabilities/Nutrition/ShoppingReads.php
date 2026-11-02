<?php

namespace App\Capabilities\Nutrition;

use App\Capabilities\Support\ReadKey;
use App\Domain\Shopping\DateRange;
use App\Models\ShoppingTicks;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;

/**
 * The shopping reads a write changes (CAP-011, CAP-017): the list with no range asked for, and the list
 * of each range named. A range is whole days, read as YYYY-MM-DD.
 */
final class ShoppingReads
{
    public static function range(string $from, string $to): DateRange
    {
        return new DateRange(TrainSnapshot::day($from), TrainSnapshot::day($to));
    }

    /** The range a write's input names, when it names both ends. */
    public static function shown(?string $from, ?string $to): ?DateRange
    {
        return $from !== null && $to !== null ? self::range($from, $to) : null;
    }

    /** @return list<string> */
    public static function keys(?DateRange ...$ranges): array
    {
        $keys = [ReadKey::for('shopping_show')];
        foreach ($ranges as $range) {
            if ($range !== null) {
                $keys[] = ReadKey::for('shopping_show', ['from' => $range->lower->format('Y-m-d'), 'to' => $range->upper->format('Y-m-d')]);
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * A manual item is on every range's list. The ones the app can have on screen: the list with no range,
     * the range the ticks are in, and the range the write was made on.
     *
     * @return list<string>
     */
    public static function everyList(User $user, ?DateRange $shown): array
    {
        return self::keys(ShoppingTicks::query()->where('user_id', $user->id)->first()?->ticked()->range, $shown);
    }
}
