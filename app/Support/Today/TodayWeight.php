<?php

namespace App\Support\Today;

use App\Domain\Model\WeighIn;
use App\Domain\Weight\CurrentWeight;
use App\Domain\Weight\TrendWeight;
use App\Models\Profile;
use App\Models\User;
use App\Models\WeighIn as WeighInRow;
use App\Support\UserDay;
use Closure;
use DateTimeImmutable;
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
        return CurrentWeight::kg(self::trendKg($user), $profile?->weight_kg);
    }

    /** WT-010: the trend over every weigh-in, kept under today's summary row. Null with no weigh-ins. */
    public static function trendKg(User $user): ?float
    {
        return self::kept($user, 'today-weight', fn (): ?float => self::history($user)->currentKg());
    }

    /**
     * WT-008: the trend on each of the user's days from the first weigh-in to the last, keyed Y-m-d, kept as trendKg()
     * is. Empty with no weigh-ins.
     *
     * @return array<string, float>
     */
    public static function trendByDay(User $user): array
    {
        return self::kept($user, 'weigh-in-trend', fn (): array => self::history($user)->byDay()) ?? [];
    }

    /**
     * $work's answer, kept under one summary row of the user's weigh-ins. Null with no weigh-ins.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T|null
     */
    private static function kept(User $user, string $name, Closure $work): mixed
    {
        $summary = WeighInRow::query()->where('user_id', $user->id)
            ->toBase()
            ->selectRaw('count(*) as n, max(updated_at) as changed, sum(weight_kg) as total, sum(unix_timestamp(weighed_at)) as moments')
            ->first();
        if ($summary === null || (int) $summary->n === 0) {
            return null;
        }
        $key = $name.':'.$user->id.':'.sha1(implode('|', [$summary->n, $summary->changed, $summary->total, $summary->moments, $user->time_zone]));

        return Cache::remember($key, self::KEEP_SECONDS, $work);
    }

    private static function history(User $user): TrendWeight
    {
        $weighIns = WeighInRow::query()->where('user_id', $user->id)->toBase()->get(['id', 'weighed_at', 'weight_kg'])
            ->map(fn (object $row) => new WeighIn((string) $row->id, new DateTimeImmutable((string) $row->weighed_at, new DateTimeZone('UTC')), (float) $row->weight_kg))
            ->values()->all();

        return new TrendWeight($weighIns, UserDay::zone($user));
    }
}
