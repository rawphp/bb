<?php

namespace App\Support\Plan;

use App\Domain\Defaults\TBL010Phases;
use App\Domain\Model\Mesocycle as DomainMesocycle;
use App\Domain\Model\PhaseType;
use App\Domain\Model\WeighIn as DomainWeighIn;
use App\Domain\Phases\NextPhase;
use App\Domain\Phases\NextPhaseOption;
use App\Domain\Phases\PhaseTraining;
use App\Domain\Phases\PhaseWarning;
use App\Domain\Phases\PhaseWarnings;
use App\Domain\Plan\MesocycleAlignment;
use App\Domain\Plan\PhaseRefusal;
use App\Domain\Plan\PhaseRefused;
use App\Domain\Plan\PhaseRules;
use App\Domain\Plan\WeekRange;
use App\Domain\Weight\TrendWeight;
use App\Models\Phase;
use App\Models\WeighIn;
use App\Support\Mesocycles\TrainSnapshot;
use DateTimeZone;

/**
 * What a phase is and what may change about it (PLAN-004, PH-006, PH-007, PH-009 to PH-012, PLAN-021, PLAN-025,
 * PLAN-028): rp-clone's PhaseDetailModel. `phase_show` lists what these allow and the writes refuse what they refuse.
 */
final class PhaseChanges
{
    /** PH-001, PH-002: the order Add a phase lists the types in. */
    public const TYPES = [PhaseType::FatLoss, PhaseType::MuscleGain, PhaseType::Maintenance, PhaseType::MiniCut];

    /** The longest phase weighed (52 weeks). */
    private const LONGEST = 52;

    public function __construct(public readonly PlanSnapshot $plan) {}

    // MARK: PLAN-004

    public function detail(Phase $phase): PhaseDetail
    {
        $range = $this->plan->weeksOf($phase);
        $weeks = $range->upper === PlanSnapshot::OPEN ? null : $range->count();
        $today = $this->plan->todayWeek;
        $started = $today !== null && $today >= $range->lower;
        $finished = $weeks !== null && $today !== null && $today >= $range->lower + $weeks;
        $weeksDone = $started ? min(max(0, $today - $range->lower), $weeks ?? PHP_INT_MAX) : 0;

        if ($finished) {
            $progressText = 'Finished';
        } elseif ($started) {
            $number = $today - $range->lower + 1;
            $progressText = $weeks === null ? "Week {$number}" : "Week {$number} of {$weeks}";
        } elseif ($today !== null) {
            $away = $range->lower - $today;
            $progressText = $away === 1 ? 'Starts in 1 week' : "Starts in {$away} weeks";
        } else {
            $progressText = 'Starts '.PlanWords::day(PlanSnapshot::day($phase->start_date));
        }

        // The trend where the phase started and where it stands, then where the rate takes it.
        $startKg = null;
        $currentKg = null;
        if ($started) {
            $trend = new TrendWeight(
                $this->plan->weighIns->map(fn (WeighIn $w) => new DomainWeighIn((string) $w->id, TrainSnapshot::day($w->weighed_at), (float) $w->weight_kg))->values()->all(),
                new DateTimeZone('UTC'),
            );
            $start = PlanSnapshot::day($phase->start_date);
            $last = $phase->end_date === null ? $this->plan->today : min(PlanSnapshot::day($phase->end_date), $this->plan->today);
            foreach ($trend->points as $point) {
                if ($startKg === null && $point->day >= $start) {
                    $startKg = $point->trendKg;
                }
                if ($point->day <= $last) {
                    $currentKg = $point->trendKg;
                }
            }
        }
        $targetKg = null;
        if (! $this->plan->trainingOnly() && $startKg !== null && $weeks !== null) {
            $counted = $weeks - $this->plan->dietBreakWeeks($range->lower, $range->upper, $phase->type);
            $targetKg = $startKg * (1 + (float) $phase->target_rate_percent_per_week / 100 * max(0, $counted));
        }

        return new PhaseDetail(
            weeks: $weeks,
            weeksDone: $weeksDone,
            progress: $weeks === null ? null : $weeksDone / max(1, $weeks),
            progressText: $progressText,
            startKg: $startKg,
            targetKg: $targetKg,
            currentKg: $currentKg,
            isCurrent: $today !== null && $range->contains($today),
            isOver: $finished,
        );
    }

    // MARK: PH-006, PLAN-021, PLAN-025

    /**
     * PH-006, PLAN-025: the lengths the phase may have, diet-break weeks not counting towards a cut's cap. A running
     * phase keeps at least the weeks up to this one. Null stands for no end (PH-005), for a type that allows it and a
     * phase with nothing after it. Empty once the phase is over.
     *
     * @return list<?int>
     */
    public function lengths(Phase $phase): array
    {
        if ($this->detail($phase)->isOver) {
            return [];
        }
        $lengths = [];
        foreach (range($this->shortest($phase), self::LONGEST) as $weeks) {
            if ($this->lengthRefusal($phase, $weeks) === null) {
                $lengths[] = $weeks;
            }
        }
        if ($this->lengthRefusal($phase, null) === null) {
            $lengths[] = null;
        }

        return $lengths;
    }

    /** Why a length is refused, or null. */
    public function lengthRefusal(Phase $phase, ?int $weeks): ?string
    {
        if ($this->detail($phase)->isOver) {
            return 'This phase is over, so its length can no longer change.';
        }
        if ($weeks === null && $this->plan->laterPhases($phase) !== []) {
            return PlanWords::refusal(PhaseRefusal::previousPhaseHasNoEnd(), $phase->type);
        }
        if ($weeks !== null && $weeks < $this->shortest($phase)) {
            $shortest = $this->shortest($phase);
            if ($shortest > TBL010Phases::row($phase->type)->minWeeks) {
                return 'This phase is in week '.($shortest).', so it must run at least '.PlanWords::weeks($shortest).'.';
            }
        }
        try {
            PhaseRules::adjustingLength(
                PlanSnapshot::domainPhase($phase), $weeks, $this->plan->grid,
                $weeks === null ? 0 : $this->dietBreaks($phase, $weeks),
            );
        } catch (PhaseRefused $refused) {
            return PlanWords::refusal($refused->refusal, $phase->type);
        }

        return null;
    }

    /** PH-006: what a new length does: where the phase ends and how to settle an end mid-mesocycle (PLAN-021). */
    public function proposeLength(Phase $phase, ?int $weeks): LengthProposal
    {
        $range = $this->plan->weeksOf($phase);
        $end = $weeks === null ? null : $this->plan->dayOfWeek($range->lower + $weeks);
        $movedEnd = null;
        $change = null;
        $fixes = null;
        if ($weeks !== null) {
            $eligible = $this->plan->unended();
            $fixes = MesocycleAlignment::phaseEndFixes(
                new WeekRange($range->lower, $range->lower + $weeks), $phase->type,
                array_map(fn (PlacedMesocycle $p) => $p->placed, $eligible), $this->plan->experience(),
            );
            // A running phase cannot move its end back before the week it is in.
            $movedEnd = $fixes?->movedEndWeek === null || $fixes->movedEndWeek - $range->lower < $this->shortest($phase)
                ? null : $this->plan->dayOfWeek($fixes->movedEndWeek);
            if ($fixes?->mesocycleLength !== null) {
                $change = [$eligible[$fixes->mesocycleLength->index], $fixes->mesocycleLength->weeks];
            }
        }
        $adjusted = PlanSnapshot::domainPhase($phase)->withDates(PlanSnapshot::day($phase->start_date), $end);
        $pastEvent = PhaseWarnings::runsPastEvent($adjusted);

        return new LengthProposal(
            weeks: $weeks,
            end: $end,
            needsFix: $fixes !== null,
            moveEnd: $movedEnd,
            mesocycleChange: $change,
            warnings: $pastEvent === null ? [] : [$pastEvent],
        );
    }

    /** The shortest length a phase may take now: TBL-010's, or up to and including this week once it runs. */
    private function shortest(Phase $phase): int
    {
        $range = $this->plan->weeksOf($phase);
        $minimum = TBL010Phases::row($phase->type)->minWeeks;
        $today = $this->plan->todayWeek;

        return $today !== null && $today >= $range->lower ? max($minimum, $today - $range->lower + 1) : $minimum;
    }

    private function dietBreaks(Phase $phase, int $weeks): int
    {
        $start = $this->plan->weeksOf($phase)->lower;

        return $this->plan->dietBreakWeeks($start, $start + $weeks, $phase->type);
    }

    // MARK: PLAN-028

    /** PLAN-028: the length that puts the phase's last week on its event's week, when that is not already so. */
    public function eventWeeks(Phase $phase): ?int
    {
        if ($phase->event_date === null || $phase->end_date === null || $this->detail($phase)->isOver) {
            return null;
        }
        $range = $this->plan->weeksOf($phase);
        $eventWeek = $this->plan->grid->weekIndexContaining(PlanSnapshot::day($phase->event_date));
        if ($eventWeek === null || $eventWeek < $range->lower || $eventWeek === $range->upper - 1 || PlanSnapshot::day($phase->event_date) < $this->plan->today) {
            return null;
        }
        $weeks = $eventWeek - $range->lower + 1;

        return in_array($weeks, $this->lengths($phase), true) ? $weeks : null;
    }

    // MARK: PH-007

    /** PH-007: only the phase that is running can be ended; it ends at the next plan-week boundary. */
    public function canEnd(Phase $phase): bool
    {
        return $this->detail($phase)->isCurrent;
    }

    // MARK: PH-009 to PH-012

    /**
     * PH-009: the types the phase may change to, what usually follows it first (PH-031, PH-033, PH-034).
     *
     * @return list<PhaseType>
     */
    public function goalTypes(Phase $phase): array
    {
        if ($this->detail($phase)->isOver) {
            return [];
        }
        $recommended = array_map(
            fn (NextPhaseOption $option) => $option->phase ?? PhaseType::MuscleGain,
            NextPhase::recommendation($phase->type),
        );
        $types = [];
        foreach ([...$recommended, ...self::TYPES] as $type) {
            if ($type !== $phase->type && ! in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * PH-009: changing the phase to another type from next week. The new type runs to the phase's end, so later
     * phases stay put; a phase with no end gets one when the new type needs it (its shortest recommended length).
     */
    public function goal(Phase $phase, PhaseType $type): GoalPreview
    {
        $range = $this->plan->weeksOf($phase);
        $inPlace = $range->lower >= $this->plan->nextWeek();
        $startWeek = $inPlace ? $range->lower : $this->plan->nextWeek();
        $row = TBL010Phases::row($type);

        $weeks = null;
        if ($range->upper !== PlanSnapshot::OPEN) {
            $weeks = $range->upper - $startWeek;
        } elseif ($row->maxWeeks !== null) {
            $weeks = (int) ($row->recommendedWeeks->lower ?? $row->minWeeks);
        }
        $trainingOnly = $this->plan->trainingOnly();
        $rate = $trainingOnly ? 0.0 : PhaseRules::defaultRatePercentPerWeek($type, $this->plan->experience());
        $breaks = $weeks === null ? 0 : $this->plan->dietBreakWeeks($startWeek, $startWeek + $weeks, $type);
        $refusal = PhaseRules::validate($type, $weeks, $trainingOnly ? null : $rate, $breaks);

        $resensitization = $type === PhaseType::Maintenance && PhaseTraining::maintenanceDefaultsToResensitization($phase->type);

        // PH-009: the running mesocycle keeps its structure; from `startWeek` its weeks follow the new type.
        $running = $this->plan->todayWeek === null ? null : $this->plan->mesocycleCoveringWeek($this->plan->todayWeek);
        $fromWeek = null;
        if ($running !== null && $this->plan->mesocycleCoveringWeek($startWeek)?->mesocycle->is($running->mesocycle)) {
            $fromWeek = $startWeek - $running->placed->startWeek + 1;
        }

        return new GoalPreview(
            type: $type,
            startWeek: $startWeek,
            weeks: $weeks,
            ratePercentPerWeek: $rate,
            refusal: $refusal === null ? null : PlanWords::refusal($refusal, $type),
            warnings: $this->goalWarnings($phase, $type, $running),
            trainingLines: PlanWords::trainingLines(PhaseTraining::mesocycleDefaults($type, $resensitization)->row),
            progressionFromWeek: $fromWeek,
            runningMesocycleName: $fromWeek === null ? null : ($running->mesocycle->name ?? 'Mesocycle'),
            replacesInPlace: $inPlace,
        );
    }

    /**
     * PH-010 to PH-012, from the mesocycle running now (or the first one in this phase).
     *
     * @return list<PhaseWarning>
     */
    private function goalWarnings(Phase $phase, PhaseType $type, ?PlacedMesocycle $running): array
    {
        $stored = ($running ?? $this->plan->mesocycleCoveringWeek($this->plan->weeksOf($phase)->lower))?->mesocycle;
        if ($stored === null) {
            return [];
        }
        $owner = $this->plan->phases->first(fn (Phase $p) => $p->id === $stored->phase_id)->type ?? $phase->type;
        $priorities = TrainSnapshot::priorities($stored);
        $mesocycle = new DomainMesocycle(
            id: (string) $stored->id, phase: $owner, weeks: (int) $stored->weeks, sessions: [],
            musclePriorities: $priorities, isResensitization: (bool) $stored->is_resensitization,
        );

        return array_values(array_filter([
            PhaseWarnings::tooManyEmphasized($type, $priorities),
            PhaseWarnings::specialisingDuringCut($type, $priorities),
            PhaseWarnings::lowVolumePlan($type, $mesocycle),
        ]));
    }

    /** A warning as the screen shows it: a sentence. */
    public static function warningText(PhaseWarning $warning): string
    {
        $text = $warning->message();

        return str_ends_with($text, '.') ? $text : "{$text}.";
    }
}
