<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * The running mesocycle's card (CurrentMesocycleView). `next_mesocycle` is set during
 * its deload week (MESO-060). `week_track` has one mark per week (MESO-008).
 */
final class TrainCurrentMesocycle extends CapabilityData
{
    /**
     * @param  list<TrainWeekMark>  $week_track
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $week_text,
        public readonly string $phase_title,
        public readonly string $dates_text,
        #[Field(items: TrainWeekMark::class)]
        public readonly array $week_track,
        public readonly ?TrainNextMesocycle $next_mesocycle,
    ) {}
}
