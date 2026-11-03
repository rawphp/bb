<?php

namespace App\Capabilities\WeighIns\Data;

use App\Capabilities\Support\Line;
use App\Capabilities\Support\Status;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The Weigh-ins screen in the More menu (WT-001 to WT-021). */
final class WeighInsShowOutput extends CapabilityData
{
    /** @param  list<WeighInRow>  $rows */
    public function __construct(
        public readonly string $title,
        #[Field(description: '"Trend weight" and its value, or "No weigh-ins yet" (WT-010).')]
        public readonly Line $trend,
        public readonly WeighInHelp $help,
        public readonly WeighInUnits $units,
        public readonly WeighInSchedule $schedule,
        #[Field(description: 'Newest first, missed scheduled days among them.', items: WeighInRow::class)]
        public readonly array $rows,
        #[Field(description: 'The pill on each row marked is_missed.')]
        public readonly Status $missed,
        #[Field(description: 'Shown when there are no weigh-ins; null otherwise.')]
        public readonly ?string $empty_message,
        public readonly StepsSection $steps,
        #[Field(description: 'The date to pass as before for older weigh-ins and steps; null when there are none.')]
        public readonly ?string $next_before,
    ) {}
}
