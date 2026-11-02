<?php

namespace App\Capabilities\Reads\Plan;

use App\Capabilities\Plan\Data\PlanAddPhase;
use App\Capabilities\Plan\Data\PlanCurrentPhase;
use App\Capabilities\Plan\Data\PlanDeloadWeek;
use App\Capabilities\Plan\Data\PlanMaintenanceSuggestion;
use App\Capabilities\Plan\Data\PlanMesocycleBand;
use App\Capabilities\Plan\Data\PlanPhaseBand;
use App\Capabilities\Plan\Data\PlanShowOutput;
use App\Domain\Model\PhaseType;
use App\Domain\Phases\NextPhase;
use App\Domain\Phases\NextPhaseOption;
use App\Domain\Phases\PhaseWarnings;
use App\Domain\Plan\DeloadCalories;
use App\Domain\Plan\PhaseRefusal;
use App\Domain\Plan\PlanRules;
use App\Models\Phase;
use App\Support\Plan\PhaseChanges;
use App\Support\Plan\PlacedMesocycle;
use App\Support\Plan\PlanSnapshot;
use App\Support\Plan\PlanWords;

/**
 * The Plan screen (PLAN-001 to PLAN-009, rp-clone's PlanTimeline and PlanTimelineModel): phases on top, mesocycles
 * under them, deload weeks and today marked (PLAN-003), the current phase, the event countdown (PH-043), the
 * Maintenance suggestion (PH-045) and the diet-break toggle on each deload (PLAN-023).
 *
 * The add-phase form's types, the prebuilt plans and the due transition are folded into this read by the train
 * contract but built from AddPhaseModel, PrebuiltPlans and PhaseTransitionModel, which #79 ports (PH-030 to PH-046).
 * Until then the form carries where a new phase starts and the PLAN-006 refusal, with no types; there are no
 * prebuilt plans and no transition.
 */
final class PlanPayload
{
    /** PLAN-023: the question before a diet break is turned off. */
    public const DIET_BREAK_OFF_WARNING = DeloadCalories::DEFICIT_DELOAD_WARNING.' Keep this deload at maintenance calories?';

    /** A plan with no phases still shows this week. */
    public static function build(?PlanSnapshot $plan): PlanShowOutput
    {
        if ($plan === null) {
            return self::noPlan();
        }

        $today = $plan->todayWeek;
        $horizon = self::horizon($plan);
        $phases = $plan->phases->values();
        $current = $plan->currentPhaseIndex();

        $bands = [];
        $countdowns = [];
        foreach ($phases as $index => $phase) {
            $range = $plan->weeksOf($phase);
            $end = $range->upper === PlanSnapshot::OPEN ? null : $range->upper;
            $days = PhaseWarnings::daysToEvent(PlanSnapshot::domainPhase($phase), $plan->today);
            if ($days !== null) {
                $countdowns[] = $days;
            }
            $bands[] = new PlanPhaseBand(
                id: (string) $phase->id,
                type: $phase->type->value,
                title: $phase->type->title(),
                start_week: $range->lower,
                weeks: ($end ?? $horizon) - $range->lower,
                dates_text: self::phaseDates($phase),
                rate_text: $plan->trainingOnly() ? null : PlanWords::rate((float) $phase->target_rate_percent_per_week),
                event_text: $days === null ? null : PlanWords::eventCountdown($days),
                state: match (true) {
                    $today !== null && $range->contains($today) => 'current',
                    $today !== null && $end !== null && $today >= $end => 'past',
                    ! PlanRules::isDetailed($index, $current) => 'outline',
                    default => 'future',
                },
            );
        }

        $covering = $today === null ? null : $plan->mesocycleCoveringWeek($today);
        $mesocycles = array_map(fn (PlacedMesocycle $p) => new PlanMesocycleBand(
            id: (string) $p->mesocycle->id,
            name: $p->mesocycle->name ?? 'Mesocycle',
            start_week: $p->placed->startWeek,
            weeks: $p->placed->nextStartWeek() - $p->placed->startWeek,
            is_current: $covering !== null && $covering->mesocycle->is($p->mesocycle),
        ), $plan->placed);

        $deloads = [];
        for ($week = 0; $week < $horizon; $week++) {
            $marks = $plan->timelineWeek($week);
            if (! $marks->isDeload) {
                continue;
            }
            $deloads[] = new PlanDeloadWeek(
                week: $week,
                start_date: $marks->start->format('Y-m-d'),
                dates_text: PlanWords::week($marks->start, $marks->dayCount),
                label: (string) $marks->deloadLabel,
                marker: $marks->dietBreakIsOff ? 'deficit_deload' : 'deload',
                diet_break_action: match (true) {
                    $marks->isPast => 'none',
                    $marks->canTurnOffDietBreak => 'turn_off',
                    $marks->dietBreakIsOff => 'turn_on',
                    default => 'none',
                },
            );
        }

        $running = $plan->runningPhase();
        $detail = $running === null ? null : (new PhaseChanges($plan))->detail($running);
        $suggestion = PhaseWarnings::maintenanceSuggestion($plan->domain->phases, $plan->today);

        return new PlanShowOutput(
            week_count: $horizon,
            today_position: self::todayPosition($plan),
            phases: $bands,
            mesocycles: $mesocycles,
            deloads: $deloads,
            current: $running === null || $detail === null ? null : new PlanCurrentPhase(
                phase_id: (string) $running->id,
                title: $running->type->title(),
                dates_text: self::phaseDates($running),
                progress_text: $detail->progressText,
                progress: $detail->progress === null ? null : round($detail->progress, 2),
            ),
            event_countdown: $countdowns === [] ? null : PlanWords::eventCountdown(min($countdowns)),
            maintenance_suggestion: $suggestion === null ? null : new PlanMaintenanceSuggestion(
                text: 'No Maintenance in the last '.PhaseWarnings::MONTHS_WITHOUT_MAINTENANCE." months. Add a {$suggestion->weeks}-week low-volume Maintenance phase?",
                weeks: $suggestion->weeks,
            ),
            diet_break_off_warning: self::DIET_BREAK_OFF_WARNING,
            add_phase: self::addPhase($plan),
            prebuilt_plans: [],
            transition: null,
        );
    }

    /** "31 Aug – 22 Nov". */
    public static function phaseDates(Phase $phase): string
    {
        return PlanWords::dates(
            PlanSnapshot::day($phase->start_date),
            $phase->end_date === null ? null : PlanSnapshot::day($phase->end_date),
        );
    }

    /** How far the timeline reaches: the last phase or mesocycle, today, and a bit past a phase with no end. */
    private static function horizon(PlanSnapshot $plan): int
    {
        $today = $plan->todayWeek ?? 0;
        $horizon = $today + 1;
        $open = false;
        foreach ($plan->phases as $phase) {
            $range = $plan->weeksOf($phase);
            if ($range->upper === PlanSnapshot::OPEN) {
                $open = true;
                $horizon = max($horizon, $range->lower + 4);
            } else {
                $horizon = max($horizon, $range->upper);
            }
        }
        $lastMesocycleEnd = 0;
        foreach ($plan->placed as $placed) {
            $horizon = max($horizon, $placed->placed->nextStartWeek());
            $lastMesocycleEnd = max($lastMesocycleEnd, $placed->placed->nextStartWeek());
        }
        if ($open) {
            $horizon = max($horizon, $today + 5, $lastMesocycleEnd + 1);
        }

        return $horizon;
    }

    /** Today as a point on the weeks axis: its week plus the share of the week gone. */
    private static function todayPosition(PlanSnapshot $plan): ?float
    {
        $week = $plan->todayWeek;
        if ($week === null) {
            return null;
        }
        $days = (int) $plan->grid->start($week)->diff($plan->today)->days;

        return round($week + $days / $plan->grid->lengthInDays($week), 2);
    }

    /**
     * PLAN-006: a new phase starts where the last one ends, or this week for an empty plan, and none can follow a
     * phase with no end. The type it opens on is what usually follows the last phase (PH-031, PH-033, PH-034).
     */
    private static function addPhase(PlanSnapshot $plan): PlanAddPhase
    {
        $last = $plan->phases->last();
        $range = $last === null ? null : $plan->weeksOf($last);
        $startWeek = match (true) {
            $range === null => $plan->todayWeek ?? 0,
            $range->upper !== PlanSnapshot::OPEN => $range->upper,
            default => null,
        };
        $after = $last === null ? [] : array_values(array_filter(array_map(
            fn (NextPhaseOption $option) => $option->phase,
            NextPhase::recommendation($last->type),
        )));

        return new PlanAddPhase(
            starts_text: $startWeek === null ? 'After the current phase' : 'Starts '.PlanWords::day($plan->dayOfWeek($startWeek)),
            refusal: $startWeek === null ? PlanWords::refusal(PhaseRefusal::previousPhaseHasNoEnd(), $last->type) : null,
            default_type: ($after[0] ?? PhaseType::FatLoss)->value,
            types: [],
        );
    }

    private static function noPlan(): PlanShowOutput
    {
        return new PlanShowOutput(
            week_count: 0,
            today_position: null,
            phases: [],
            mesocycles: [],
            deloads: [],
            current: null,
            event_countdown: null,
            maintenance_suggestion: null,
            diet_break_off_warning: self::DIET_BREAK_OFF_WARNING,
            add_phase: new PlanAddPhase(
                starts_text: 'Finish setting up your plan first',
                refusal: 'There is no plan yet. Finish onboarding to build one.',
                default_type: PhaseType::FatLoss->value,
                types: [],
            ),
            prebuilt_plans: [],
            transition: null,
        );
    }
}
