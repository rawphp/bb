<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A mesocycle. A saved one fills `saved` (MesocycleView, ProgressionRatesView,
 * MESO-030 to MESO-034, MESO-040 to MESO-046); a draft fills `build`, the create flow's state
 * (MesocycleBuildView, BuildSteps, MESO-003 to MESO-007, MESO-013, MESO-014, MESO-020 to
 * MESO-023). Exactly one of the two is set.
 */
final class MesocycleShowOutput extends CapabilityData
{
    /**
     * @param  list<MesocyclePriorityOption>  $priority_options
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $name,
        #[Field(items: MesocyclePriorityOption::class)]
        public readonly array $priority_options,
        public readonly ?MesocycleDetail $saved,
        public readonly ?MesocycleBuild $build,
    ) {}
}
