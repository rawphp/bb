<?php

namespace Tests\Support;

use App\Models\Mesocycle;
use App\Models\Phase;
use App\Models\User;
use App\Models\WeighIn;
use Tests\Unit\Capabilities\Reads\Plan\PlanWorld;

/**
 * PlanWorld as rows, for the Plan capabilities' seam tests: TrainSeed's plan (weeks start Monday 31 August 2026),
 * Fat loss to 23 November, Maintenance to 21 December, Muscle gain to 15 March 2027; Meso 1 (6 weeks from 31
 * August), Meso 2 (6 weeks from 12 October) and Meso 3 (4 weeks from 23 November); daily weigh-ins from 31 August.
 * Use it with the clock at Wednesday 14 October 2026, in week 6.
 */
final class PlanSeed
{
    /** @return array{0: User, 1: list<Phase>, 2: list<Mesocycle>} */
    public static function world(?string $event = null): array
    {
        $user = TrainSeed::user();
        $phases = [];
        foreach (PlanWorld::phases() as $index => $phase) {
            $phases[] = Phase::factory()->for($user)->create([
                'id' => $phase->id, 'type' => $phase->type, 'start_date' => $phase->start_date, 'end_date' => $phase->end_date,
                'target_rate_percent_per_week' => $phase->target_rate_percent_per_week, 'event_date' => $index === 0 ? $event : null,
            ]);
        }
        $mesocycles = [];
        foreach (PlanWorld::mesocycles() as $mesocycle) {
            $mesocycles[] = TrainSeed::mesocycle($user, [
                'id' => $mesocycle->id, 'name' => $mesocycle->name, 'phase_id' => $mesocycle->phase_id,
                'start_date' => $mesocycle->start_date, 'weeks' => $mesocycle->weeks,
            ]);
        }
        foreach (PlanWorld::weighIns() as $weighIn) {
            WeighIn::factory()->for($user)->create(['weight_kg' => $weighIn->weight_kg, 'weighed_at' => $weighIn->weighed_at]);
        }

        return [$user, $phases, $mesocycles];
    }
}
