<?php

namespace App\Actions\WeighIns;

use App\Capabilities\WeighIns\Data\WaistInput;
use App\Capabilities\WeighIns\Data\WeightInput;
use App\Domain\WeighIns\BodyMeasures;
use App\Models\User;
use App\Models\WeighIn;
use App\Support\UserDay;
use App\Support\WeighIns\ReadingTime;

/**
 * WT-004: edit a weigh-in's time, weight and waist. The edit sends the whole reading, so no waist clears it
 * (WT-021). Where it came from does not change: a corrected bridge reading is still the bridge's.
 */
final class UpdateWeighIn
{
    /** @return list<string> the user's local days, Y-m-d, whose weigh-ins changed: the old day and the new one (SET-012) */
    public function __invoke(User $user, string $id, string $weighedAt, WeightInput $weight, ?WaistInput $waist): array
    {
        $at = ReadingTime::instant($weighedAt);
        $kg = BodyMeasures::kg($weight->value, $weight->unit);
        $weighIn = WeighIn::query()->where('user_id', $user->id)->findOrFail($id);
        $before = UserDay::dayOf($user, $weighIn->weighed_at)->format('Y-m-d');

        $weighIn->forceFill([
            'weight_kg' => $kg,
            'waist_cm' => $waist === null ? null : BodyMeasures::waistCm($waist->value, $waist->unit),
            'weighed_at' => $at,
        ])->save();

        return array_values(array_unique([$before, UserDay::dayOf($user, $weighIn->weighed_at)->format('Y-m-d')]));
    }
}
