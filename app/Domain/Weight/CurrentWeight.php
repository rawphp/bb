<?php

namespace App\Domain\Weight;

use App\Domain\Model\WeighIn;
use DateTimeZone;

/**
 * WT-011: the weight everything works from is the trend weight; before any weigh-in it is the
 * profile's weight, the one entered at onboarding. Every "current weight" comes from here, never
 * a single reading. For now, $trendKg is TrendWeight::currentKg(), the last day's trend. A past
 * day's trend comes from TrendWeight::trendKg(), which is null after the last weigh-in, so today's
 * date never goes there.
 */
final class CurrentWeight
{
    /**
     * The profile's weight may be unknown (the schedule snapshot may carry none). Then there is
     * no current weight until a weigh-in.
     *
     * @return ($profileKg is float ? float : float|null)
     */
    public static function kg(?float $trendKg, ?float $profileKg): ?float
    {
        return $trendKg ?? $profileKg;
    }

    /**
     * The current weight from the raw weigh-ins.
     *
     * @param  list<WeighIn>  $weighIns
     * @return ($profileKg is float ? float : float|null)
     */
    public static function fromWeighIns(array $weighIns, ?float $profileKg, DateTimeZone $timeZone): ?float
    {
        return self::kg((new TrendWeight($weighIns, $timeZone))->currentKg(), $profileKg);
    }
}
