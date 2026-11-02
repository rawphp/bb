<?php

namespace App\Capabilities\Plan\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * The Plan screen (PLAN-001 to PLAN-009): the timeline of phases over
 * mesocycles with deloads and today marked (PLAN-003), the current phase, the
 * event countdown (PH-043) and the Maintenance suggestion (PH-045).
 *
 * It also carries what the screens it opens need: the Add a phase form
 * (PLAN-006, PLAN-020), RP's prebuilt plans (PLAN-030) and, when a phase has
 * ended or was ended (PH-007), the transition screen (PH-030 to PH-046).
 * `transition` non-null means a transition is due; the router sends the user
 * to it. Timeline positions are plan-week indexes from 0 over `week_count`
 * weeks; `today_position` is today's week plus the share of it gone.
 */
final class PlanShowOutput extends CapabilityData
{
    /**
     * @param  list<PlanPhaseBand>  $phases
     * @param  list<PlanMesocycleBand>  $mesocycles
     * @param  list<PlanDeloadWeek>  $deloads
     * @param  list<PlanPrebuilt>  $prebuilt_plans
     */
    public function __construct(
        public readonly int $week_count,
        public readonly ?float $today_position,
        #[Field(items: PlanPhaseBand::class)]
        public readonly array $phases,
        #[Field(items: PlanMesocycleBand::class)]
        public readonly array $mesocycles,
        #[Field(items: PlanDeloadWeek::class)]
        public readonly array $deloads,
        public readonly ?PlanCurrentPhase $current,
        public readonly ?string $event_countdown,
        public readonly ?PlanMaintenanceSuggestion $maintenance_suggestion,
        public readonly string $diet_break_off_warning,
        public readonly PlanAddPhase $add_phase,
        #[Field(items: PlanPrebuilt::class)]
        public readonly array $prebuilt_plans,
        public readonly ?PlanTransition $transition,
    ) {}
}
