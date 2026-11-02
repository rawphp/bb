<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * The Train tab (TrainView, MesocyclesView, CurrentMesocycleView): today's session,
 * the current mesocycle with the next-mesocycle prompt (MESO-060, MESO-064), the unfinished
 * draft (MESO-007), every mesocycle grouped current, planned, past (MESO-001), and the + menu
 * (MESO-002).
 */
final class TrainShowOutput extends CapabilityData
{
    /**
     * @param  list<TrainMesocycleSection>  $sections
     * @param  list<TrainCreateRoute>  $create_routes
     */
    public function __construct(
        public readonly ?TrainTodaySession $todays_session,
        public readonly ?TrainCurrentMesocycle $current,
        public readonly ?TrainMesocycleDraft $draft,
        #[Field(items: TrainMesocycleSection::class)]
        public readonly array $sections,
        #[Field(items: TrainCreateRoute::class)]
        public readonly array $create_routes,
    ) {}
}
