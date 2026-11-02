<?php

namespace App\Support\Today;

use App\Domain\Model\WeighIn;
use App\Domain\Weight\CurrentWeight;
use App\Models\Profile;
use App\Models\User;
use App\Models\WeighIn as WeighInRow;
use DateTimeZone;

/**
 * WT-011: the user's current weight, from the weigh-in trend, else the profile's. Null with neither. Every weigh-in is
 * read: the trend is a moving average seeded from the first one, so a window would change it.
 */
final class TodayWeight
{
    public static function kg(User $user, ?Profile $profile): ?float
    {
        $weighIns = WeighInRow::query()->where('user_id', $user->id)->get(['id', 'weighed_at', 'weight_kg'])
            ->map(fn (WeighInRow $row) => new WeighIn((string) $row->id, DayPlans::instant($row->weighed_at), (float) $row->weight_kg))
            ->values()->all();

        return CurrentWeight::fromWeighIns($weighIns, $profile?->weight_kg, new DateTimeZone('UTC'));
    }
}
