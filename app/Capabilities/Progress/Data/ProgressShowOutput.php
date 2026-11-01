<?php

namespace App\Capabilities\Progress\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The Progress tab (ANA-001 to ANA-011, ONB-032). */
final class ProgressShowOutput extends CapabilityData
{
    /**
     * @param  list<ProgressScope>  $scopes
     * @param  list<Line>  $summary
     * @param  list<PhaseHistoryEntry>  $phase_history
     * @param  list<MesocycleHistoryEntry>  $mesocycle_history
     * @param  list<UsedExercise>  $exercises
     */
    public function __construct(
        public readonly string $title,
        #[Field(items: ProgressScope::class)]
        public readonly array $scopes,
        #[Field(description: 'Replaces the chart and summary until there are 4 weigh-ins; null after.')]
        public readonly ?ProgressEmpty $empty,
        #[Field(description: 'Shown instead of the chart when the chosen range has no weigh-ins.')]
        public readonly ?string $scope_message,
        public readonly ?ProgressChart $chart,
        #[Field(description: 'ANA-002: start weight, change so far and goal weight.', items: Line::class)]
        public readonly array $summary,
        public readonly ?ProgressShare $share,
        public readonly ?ThisWeek $this_week,
        public readonly ?ReviewCard $review_card,
        #[Field(description: 'Null when there is no step data (ANA-005).')]
        public readonly ?StepsCard $steps,
        #[Field(items: PhaseHistoryEntry::class)]
        public readonly array $phase_history,
        #[Field(items: MesocycleHistoryEntry::class)]
        public readonly array $mesocycle_history,
        #[Field(description: 'Exercises with a logged set, most recently used first (ANA-009).', items: UsedExercise::class)]
        public readonly array $exercises,
    ) {}
}
