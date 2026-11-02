<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The next-mesocycle card (MESO-060). */
final class NextMesocycle extends CapabilityData
{
    /**
     * @param  list<NextMesocycleOption>  $options
     */
    public function __construct(
        public readonly string $mesocycle_id,
        #[Field(description: '"Build your next mesocycle"')] public readonly string $title,
        #[Field(description: '"Starts Monday, Nov 9"')] public readonly string $start_text,
        #[Field(items: NextMesocycleOption::class)] public readonly array $options,
        #[Field(description: 'Offered when a week of active rest is recommended.')] public readonly ?ActiveRest $active_rest,
    ) {}
}
