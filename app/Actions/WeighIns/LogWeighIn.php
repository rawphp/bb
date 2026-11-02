<?php

namespace App\Actions\WeighIns;

use App\Capabilities\WeighIns\Data\WaistInput;
use App\Capabilities\WeighIns\Data\WeightInput;
use App\Domain\WeighIns\BodyMeasures;
use App\Enums\MeasurementSource;
use App\Models\User;
use App\Models\WeighIn;
use Carbon\CarbonImmutable;

/**
 * WT-002, WT-006: log a weigh-in on any day, in kg converted from the user's unit, with an optional waist (WT-021).
 * It is stored at the wall-clock time the user gave, never the server's clock, so a reading replayed from the
 * offline outbox lands on the day it was taken (OFF-030).
 *
 * A create under the ID of the user's existing weigh-in returns it unchanged, so a replay never adds a second one
 * (CAP-019). WT-003: the Shortcuts bridge posts the latest reading each time it runs; a bridge reading at a time the
 * user already has a bridge reading at is the same one, and is imported once.
 */
final class LogWeighIn
{
    /** @param  WeighIn|null  $existing  the user's weigh-in with `$id`, returned as it is; null creates one under it */
    public function __invoke(User $user, ?WeighIn $existing, string $id, string $weighedAt, WeightInput $weight, ?WaistInput $waist, MeasurementSource $source): WeighIn
    {
        if ($existing !== null) {
            return $existing;
        }

        $at = self::wallClock($weighedAt);
        $kg = BodyMeasures::kg($weight->value, $weight->unit);

        if ($source === MeasurementSource::Shortcut) {
            $imported = WeighIn::query()->where('user_id', $user->id)
                ->where('source', MeasurementSource::Shortcut)
                ->where('weighed_at', $at)
                ->first();
            if ($imported !== null) {
                return $imported;
            }
        }

        $weighIn = (new WeighIn)->forceFill([
            'id' => $id,
            'user_id' => $user->id,
            'weight_kg' => $kg,
            'waist_cm' => $waist === null ? null : BodyMeasures::waistCm($waist->value, $waist->unit),
            'weighed_at' => $at,
            'source' => $source,
        ]);
        $weighIn->save();

        return $weighIn;
    }

    /** The time as the user's clock read it: "2026-10-10T08:35:00+01:00" is 08:35 on the 10th. */
    public static function wallClock(string $time): string
    {
        return CarbonImmutable::parse($time)->format('Y-m-d H:i:s');
    }
}
