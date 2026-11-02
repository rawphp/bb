<?php

namespace App\Capabilities\Nutrition;

use App\Capabilities\Support\ReadKey;
use App\Domain\Shopping\DateRange;
use App\Models\ShoppingTicks;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The shopping reads a write changes (CAP-011, CAP-017): the list with no range asked for, and the list
 * of each range named. A range is whole days, read as YYYY-MM-DD.
 */
final class ShoppingReads
{
    public static function range(string $from, string $to): DateRange
    {
        $utc = new DateTimeZone('UTC');

        return new DateRange(new DateTimeImmutable($from, $utc), new DateTimeImmutable($to, $utc));
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
     * A manual item is on every range's list: the list with no range, and the range the ticks are in,
     * which is the one the app shows the user's ticks on.
     *
     * @return list<string>
     */
    public static function everyList(User $user): array
    {
        return self::keys(ShoppingTicks::query()->where('user_id', $user->id)->first()?->ticked()->range);
    }
}
