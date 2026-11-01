<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Adds an empty day after a running mesocycle's days, in every week (MESO-045).
 * The server labels it in the mesocycle's style. `day_id` is the client's ULID
 * for the new day (CAP-019).
 */
final class MesocycleDayAddInput extends CapabilityData
{
    public function __construct(
        public readonly string $mesocycle_id,
        public readonly string $day_id,
    ) {}

    public static function rules(): array
    {
        return ['day_id' => ['required', 'ulid']];
    }
}
