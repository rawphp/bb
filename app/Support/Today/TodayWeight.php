<?php

namespace App\Support\Today;

use App\Domain\Model\WeighIn;
use App\Domain\Weight\CurrentWeight;
use App\Domain\Weight\TrendWeight;
use App\Models\Profile;
use App\Models\User;
use App\Models\WeighIn as WeighInRow;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;

/**
 * WT-011: the user's current weight, from the weigh-in trend, else the profile's. Null with neither.
 *
 * The trend is a moving average seeded from the first weigh-in, so it needs every weigh-in, but only when one
 * changed. A read takes one summary row of the user's weigh-ins and keeps the trend under it, so an added, edited or
 * deleted weigh-in (through a model or not) reads a new key and the history is read again.
 */
final class TodayWeight
{
    private const KEEP_SECONDS = 7 * 24 * 60 * 60;

    public static function kg(User $user, ?Profile $profile): ?float
    {
        $summary = WeighInRow::query()->where('user_id', $user->id)
            ->toBase()
            ->selectRaw('count(*) as n, max(updated_at) as changed, sum(weight_kg) as total, sum(unix_timestamp(weighed_at)) as moments')
            ->first();
        if ($summary === null || (int) $summary->n === 0) {
            return CurrentWeight::kg(null, $profile?->weight_kg);
        }
        $key = 'today-weight:'.$user->id.':'.sha1(implode('|', [$summary->n, $summary->changed, $summary->total, $summary->moments]));

        /** @var float|null $trendKg */
        $trendKg = Cache::remember($key, self::KEEP_SECONDS, fn (): ?float => self::trendKg($user));

        return CurrentWeight::kg($trendKg, $profile?->weight_kg);
    }

    private static function trendKg(User $user): ?float
    {
        $weighIns = WeighInRow::query()->where('user_id', $user->id)->get(['id', 'weighed_at', 'weight_kg'])
            ->map(fn (WeighInRow $row) => new WeighIn((string) $row->id, DayPlans::instant($row->weighed_at), (float) $row->weight_kg))
            ->values()->all();

        return (new TrendWeight($weighIns, new DateTimeZone('UTC')))->currentKg();
    }
}
