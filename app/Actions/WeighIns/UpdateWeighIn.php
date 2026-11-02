<?php

namespace App\Actions\WeighIns;

use App\Capabilities\WeighIns\Data\WaistInput;
use App\Capabilities\WeighIns\Data\WeightInput;
use App\Domain\WeighIns\BodyMeasures;
use App\Models\User;
use App\Models\WeighIn;

/**
 * WT-004: edit a weigh-in's time, weight and waist. The edit sends the whole reading, so no waist clears it
 * (WT-021). Where it came from does not change: a corrected bridge reading is still the bridge's.
 */
final class UpdateWeighIn
{
    /** @return list<string> the days, Y-m-d, whose weigh-ins changed: the old day and the new one */
    public function __invoke(User $user, string $id, string $weighedAt, WeightInput $weight, ?WaistInput $waist): array
    {
        $weighIn = WeighIn::query()->where('user_id', $user->id)->findOrFail($id);
        $before = $weighIn->weighed_at->format('Y-m-d');

        $weighIn->forceFill([
            'weight_kg' => BodyMeasures::kg($weight->value, $weight->unit),
            'waist_cm' => $waist === null ? null : BodyMeasures::waistCm($waist->value, $waist->unit),
            'weighed_at' => LogWeighIn::wallClock($weighedAt),
        ])->save();

        return array_values(array_unique([$before, $weighIn->weighed_at->format('Y-m-d')]));
    }
}
