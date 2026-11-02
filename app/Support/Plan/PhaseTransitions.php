<?php

namespace App\Support\Plan;

use App\Domain\Defaults\TBL010Phases;
use App\Domain\Defaults\TBL020MesocycleLength;
use App\Domain\Energy\EnergyRules;
use App\Domain\Macros\MacroTargets;
use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\HeightUnit;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Profile as DomainProfile;
use App\Domain\Model\Sex;
use App\Domain\Model\StepPlanDay;
use App\Domain\Phases\MaintenanceLength;
use App\Domain\Phases\NextPhase;
use App\Domain\Phases\NextPhaseOption;
use App\Domain\Phases\PhaseReadiness;
use App\Domain\Phases\PhaseSummary;
use App\Domain\Phases\PhaseTraining;
use App\Domain\Phases\PhaseWarnings;
use App\Domain\Phases\ReadinessFailure;
use App\Domain\Phases\StartingCalories;
use App\Domain\Plan\MesocycleAlignment;
use App\Domain\Plan\PhaseRules;
use App\Domain\Plan\PlannedMesocycle;
use App\Domain\Review\StepPlanUpdate;
use App\Models\Phase;
use App\Support\Today\TodaySnapshot;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The phase transition (PH-030 to PH-046): rp-clone's PhaseTransitionModel. A transition is due once the last phase
 * of the plan is in its final week or over, so it opens when the phase reaches its end and when `phase_end` ends it
 * at the next boundary (PH-007). Nothing is due while a phase follows it: what comes next is already saved.
 * `plan_show` lists what these offer and `phase_transition_complete` refuses what they do not.
 */
final class PhaseTransitions
{
    public const FAT_LOSS_AFTER_FAT_LOSS = 'Fat loss right after Fat loss is not recommended. Run a Maintenance phase between them.';

    /** PH-036 */
    public const TEMPLATES_AFTER_FAT_LOSS = ['resensitization', 'balanced'];

    public const STANDARD = 'standard';

    private ?Phase $due;

    private int $endWeek = 0;

    public function __construct(public readonly PlanSnapshot $plan, public readonly PhaseIntake $intake = new PhaseIntake)
    {
        $last = $plan->phases->last();
        $range = $last === null ? null : $plan->weeksOf($last);
        $today = $plan->todayWeek;
        $this->due = $range !== null && $today !== null && $range->upper !== PlanSnapshot::OPEN && $today >= $range->upper - 1 ? $last : null;
        if ($range !== null && $this->due !== null) {
            $this->endWeek = $range->upper;
        }
    }

    /** The phase whose transition is due, if any. */
    public function due(): ?Phase
    {
        return $this->due;
    }

    /** The first day of what comes next: the day the ending phase ends. */
    public function start(): DateTimeImmutable
    {
        return $this->plan->dayOfWeek($this->endWeek);
    }

    private function ending(): Phase
    {
        return $this->due ?? throw new \LogicException('No transition is due.');
    }

    private function endingType(): PhaseType
    {
        return $this->ending()->type;
    }

    // MARK: PH-030 summary

    public function summary(): PhaseSummary
    {
        $phase = $this->ending();
        $weights = (new PhaseChanges($this->plan))->weights($phase);
        $profileKg = (float) ($this->plan->profile->weight_kg ?? 0);
        $startKg = $weights->startKg ?? $profileKg;

        return new PhaseSummary(
            type: $phase->type,
            startTrendWeightKg: $startKg,
            endTrendWeightKg: $weights->currentKg ?? $startKg,
            weeks: max(0, $this->endWeek - $this->plan->weeksOf($phase)->lower),
            adherence: $this->intake->adherence ?? 0.0,
            mesocyclesCompleted: count(array_filter(
                $this->plan->placed,
                fn (PlacedMesocycle $p) => (string) $p->mesocycle->phase_id === (string) $phase->id && ! $p->isEnded && $p->placed->nextStartWeek() <= $this->endWeek,
            )),
        );
    }

    // MARK: PH-030 to PH-034 the next phase

    /**
     * The recommendation first (the first is the default), then every other phase type.
     *
     * @return list<NextPhaseOption>
     */
    public function options(): array
    {
        $transition = NextPhase::transition($this->summary());

        return [...$transition->recommendation, ...array_map(fn (PhaseType $type) => NextPhaseOption::phase($type), $transition->alternatives)];
    }

    public function isRecommended(NextPhaseOption $option): bool
    {
        foreach (NextPhase::recommendation($this->endingType()) as $recommended) {
            if ($recommended == $option) {
                return true;
            }
        }

        return false;
    }

    /** The option `phase_transition_complete` names: `choice` and `gain_first`, as `plan_show` offers it. */
    public function find(PhaseType $choice, bool $gainFirst): ?NextPhaseOption
    {
        foreach ($this->options() as $option) {
            if ($gainFirst ? $option->isGainFirst() && $choice === PhaseType::MuscleGain : $option->phase === $choice) {
                return $option;
            }
        }

        return null;
    }

    /** The type of the first phase the option starts (PH-032: Gain first starts with Muscle gain). */
    public static function firstType(NextPhaseOption $option): PhaseType
    {
        return $option->phase ?? PhaseType::MuscleGain;
    }

    public function title(NextPhaseOption $option): string
    {
        return $option->isGainFirst() ? 'Gain first' : self::firstType($option)->title();
    }

    public function detail(NextPhaseOption $option): string
    {
        if ($option->isGainFirst()) {
            return 'One Muscle gain mesocycle straight after the cut, then a Maintenance mesocycle with resensitization training, then Muscle gain again.';
        }

        return match ([$option->phase, $this->endingType()]) {
            [PhaseType::Maintenance, PhaseType::FatLoss], [PhaseType::Maintenance, PhaseType::MiniCut] => 'Hold the new lower weight and let calories and training recover.',
            [PhaseType::Maintenance, PhaseType::MuscleGain] => '4 to 8 weeks to consolidate the gain.',
            [PhaseType::MiniCut, PhaseType::MuscleGain] => 'A short cut to trim fat gained.',
            [PhaseType::FatLoss, PhaseType::Maintenance], [PhaseType::MuscleGain, PhaseType::Maintenance] => 'Start the next push.',
            default => '',
        };
    }

    public function isMaintenanceAfterFatLoss(NextPhaseOption $option): bool
    {
        return $option->phase === PhaseType::Maintenance && $this->endingType()->isFatLoss();
    }

    private function mesocycleWeeks(): int
    {
        return TBL020MesocycleLength::row($this->plan->experience())->defaultWeeks;
    }

    /** PH-031: how long Maintenance after a cut may run. */
    public function maintenanceLength(NextPhaseOption $option): ?MaintenanceLength
    {
        $weeks = $this->summary()->weeks;
        if (! $this->isMaintenanceAfterFatLoss($option) || $weeks <= 0) {
            return null;
        }
        $yearAgo = $this->plan->today->modify('-1 year');
        $start = PlanSnapshot::day($this->ending()->start_date);
        $cuts = $this->plan->phases->filter(fn (Phase $p) => $p->type->isFatLoss() && PlanSnapshot::day($p->start_date) > $yearAgo && PlanSnapshot::day($p->start_date) <= $start)->count();

        return NextPhase::maintenanceAfterFatLoss($weeks, $cuts, false, $this->mesocycleWeeks());
    }

    /** The length the option opens on; null for Gain first, whose phases have their own. */
    public function defaultWeeks(NextPhaseOption $option): ?int
    {
        if ($option->isGainFirst()) {
            return null;
        }
        if ($this->plan->trainingOnly()) {
            return $this->mesocycleWeeks();
        }
        $length = $this->maintenanceLength($option);
        if ($length !== null) {
            return $length->defaultWeeks;
        }
        $type = self::firstType($option);
        $row = TBL010Phases::row($type);

        return match ($type) {
            PhaseType::Maintenance => $this->endingType() === PhaseType::MuscleGain ? NextPhase::maintenanceAfterGainWeeks()->lower : 4,
            PhaseType::MiniCut => 4,
            PhaseType::FatLoss, PhaseType::MuscleGain => (int) ($row->recommendedWeeks->lower ?? $row->minWeeks),
        };
    }

    /**
     * The lengths the option's phase may have (PH-031, PH-033, TBL-010, PLAN-025); none for Gain first.
     *
     * @return list<int>
     */
    public function lengths(NextPhaseOption $option): array
    {
        if ($option->isGainFirst()) {
            return [];
        }
        $type = self::firstType($option);
        $row = TBL010Phases::row($type);
        $length = $this->maintenanceLength($option);
        [$lower, $upper] = match (true) {
            $length !== null => [$length->allowedWeeks->lower, $length->allowedWeeks->upper],
            $type === PhaseType::Maintenance && $this->endingType() === PhaseType::MuscleGain => [NextPhase::maintenanceAfterGainWeeks()->lower, NextPhase::maintenanceAfterGainWeeks()->upper],
            $row->maxWeeks === null => [$row->minWeeks, NewPhases::LONGEST],
            default => [$row->minWeeks, $type->isFatLoss() && ! $this->plan->trainingOnly() ? $row->maxWeeks + 12 : $row->maxWeeks],
        };
        $lengths = [];
        foreach (range((int) $lower, (int) $upper) as $weeks) {
            if (PhaseRules::validate($type, $weeks, null, $this->dietBreaks($type, $this->endWeek, $weeks)) === null) {
                $lengths[] = $weeks;
            }
        }

        return $lengths;
    }

    /**
     * What confirming creates, in order: one phase, or Gain first's three (PH-032). The last Muscle gain is open
     * ended in the book, but a Muscle gain needs an end here, so it takes its shortest recommended length.
     *
     * @return list<array{type: PhaseType, start_week: int, weeks: int}>
     */
    public function planned(NextPhaseOption $option, ?int $weeks): array
    {
        if (! $option->isGainFirst()) {
            return [['type' => self::firstType($option), 'start_week' => $this->endWeek, 'weeks' => (int) ($weeks ?? $this->defaultWeeks($option))]];
        }
        $gain = TBL010Phases::muscleGain();
        $first = max($gain->minWeeks, $this->mesocycleWeeks());
        $middle = PhaseTraining::defaultResensitizationWeeks();

        return [
            ['type' => PhaseType::MuscleGain, 'start_week' => $this->endWeek, 'weeks' => $first],
            ['type' => PhaseType::Maintenance, 'start_week' => $this->endWeek + $first, 'weeks' => $middle],
            ['type' => PhaseType::MuscleGain, 'start_week' => $this->endWeek + $first + $middle, 'weeks' => (int) ($gain->recommendedWeeks->lower ?? $gain->minWeeks)],
        ];
    }

    /** The rate a planned phase is saved with: none in training-only mode (PLAN-007). */
    public function rate(PhaseType $type): float
    {
        return $this->plan->trainingOnly() ? 0.0 : PhaseRules::defaultRatePercentPerWeek($type, $this->plan->experience());
    }

    /** TBL-010: why a phase of the choice is refused, or null. */
    public function refusal(NextPhaseOption $option, ?int $weeks): ?string
    {
        foreach ($this->planned($option, $weeks) as $phase) {
            $refused = PhaseRules::validate(
                $phase['type'], $phase['weeks'], $this->plan->trainingOnly() ? null : $this->rate($phase['type']),
                $this->dietBreaks($phase['type'], $phase['start_week'], $phase['weeks']),
            );
            if ($refused !== null) {
                return PlanWords::refusal($refused, $phase['type']);
            }
        }

        return null;
    }

    /** PLAN-025: the deloads of the mesocycles that would fill a cut fall inside it at maintenance calories. */
    private function dietBreaks(PhaseType $type, int $startWeek, int $weeks): int
    {
        if (! $type->isFatLoss() || $this->plan->trainingOnly() || $weeks < 1) {
            return 0;
        }
        $mesocycles = MesocycleAlignment::mesocycles(MesocycleAlignment::proposal($type, $weeks, $this->plan->experience()), $startWeek);

        return count(array_filter($mesocycles, fn (PlannedMesocycle $m) => $m->deloadWeek() < $startWeek + $weeks));
    }

    // MARK: PH-036, PH-039 training

    /**
     * The first mesocycle's training choices: resensitization or balanced for Maintenance after Fat loss (PH-036),
     * otherwise the one standard template.
     *
     * @return list<string>
     */
    public function templates(NextPhaseOption $option): array
    {
        return $option->phase === PhaseType::Maintenance && PhaseTraining::maintenanceDefaultsToResensitization($this->endingType())
            ? self::TEMPLATES_AFTER_FAT_LOSS : [self::STANDARD];
    }

    /** @return list<string> */
    public static function trainingLines(NextPhaseOption $option, string $template): array
    {
        return PlanWords::trainingLines(PhaseTraining::mesocycleDefaults(self::firstType($option), $template === 'resensitization')->row);
    }

    // MARK: PH-035 starting calories

    /** Measured expenditure (REV-021) is not read on the server yet, so the formula (NUT-002) stands in. Null in training-only mode. */
    public function startingCalories(): ?StartingCalories
    {
        $profile = $this->domainProfile();
        if ($profile === null || $this->plan->trainingOnly()) {
            return null;
        }
        $summary = $this->summary();
        $bmr = EnergyRules::bmr($profile, $summary->endTrendWeightKg, $this->plan->today, new DateTimeZone('UTC'));
        $steps = [];
        foreach (TodaySnapshot::json($this->plan->profile?->step_plan) as $day) {
            $day = TodaySnapshot::json($day);
            $steps[] = new StepPlanDay((int) ($day['weekday'] ?? 0), (int) ($day['min'] ?? 0), (int) ($day['max'] ?? 0));
        }
        $formula = EnergyRules::dailyExpenditure($bmr, EnergyRules::activityFactor(StepPlanUpdate::plannedDailySteps($steps)), 0);

        return StartingCalories::resolve(null, $summary->weeks, false, $formula);
    }

    // MARK: PH-044 step up

    /** Only for Maintenance after Fat loss with nutrition on. */
    public function hasStepUp(NextPhaseOption $option): bool
    {
        return $this->isMaintenanceAfterFatLoss($option) && ! $this->plan->trainingOnly();
    }

    /** @return list<int> the step sizes offered, kcal a day */
    public static function steps(): array
    {
        return [250, 500];
    }

    // MARK: PH-037, PH-040, PH-046 warnings

    /**
     * PH-034, PH-038 and TBL-010 at a length. They warn and never block.
     *
     * @return list<string>
     */
    public function lengthWarnings(NextPhaseOption $option, int $weeks): array
    {
        $type = self::firstType($option);
        $ending = $this->ending();
        $warnings = [];
        if ($type->isFatLoss()) {
            if ($ending->type->isFatLoss()) {
                $warnings[] = self::FAT_LOSS_AFTER_FAT_LOSS;
            } elseif ($ending->type === PhaseType::Maintenance) {
                $phases = $this->plan->phases->values();
                $before = $phases->count() >= 2 ? $phases[$phases->count() - 2] : null;
                $short = $before !== null && $before->type->isFatLoss()
                    ? PhaseWarnings::shortMaintenanceWarning($this->summary()->weeks, $this->plan->weeksOf($before)->count()) : null;
                if ($short !== null) {
                    $warnings[] = PhaseChanges::warningText($short);
                }
            }
        }
        if ($type === PhaseType::MiniCut && $ending->type === PhaseType::MuscleGain) {
            $ratio = PhaseWarnings::miniCutLength($this->summary()->weeks, $weeks);
            if ($ratio !== null) {
                $warnings[] = PhaseChanges::warningText($ratio);
            }
        }
        if ($type === PhaseType::FatLoss) {
            array_push($warnings, ...self::fatLossLengthWarnings($weeks));
            $loss = $this->plan->trainingOnly() ? null
                : self::lossOverTenPercent($this->rate($type), $weeks - $this->dietBreaks($type, $this->endWeek, $weeks));
            if ($loss !== null) {
                $warnings[] = $loss;
            }
        }

        return $warnings;
    }

    /**
     * PH-040 for an ending Muscle gain, and PH-046's fat and carb checks before a new Fat loss or Muscle gain, from
     * the final week's targets. Nutrition only.
     *
     * @return list<string>
     */
    public function optionWarnings(NextPhaseOption $option): array
    {
        $profile = $this->domainProfile();
        if ($profile === null || $this->plan->trainingOnly()) {
            return [];
        }
        $summary = $this->summary();
        $warnings = [];
        if ($this->endingType() === PhaseType::MuscleGain) {
            $fat = PhaseWarnings::bodyFatWarning($profile->sex, $profile->bodyFatBucket, $profile->weightKg, $summary->endTrendWeightKg);
            if ($fat !== null) {
                $warnings[] = PhaseChanges::warningText($fat);
            }
        }
        $lean = MacroTargets::leanMassKg(new DomainProfile(
            name: $profile->name, sex: $profile->sex, birthDate: $profile->birthDate, heightCm: $profile->heightCm,
            weightKg: $summary->endTrendWeightKg, weightUnit: $profile->weightUnit, heightUnit: $profile->heightUnit,
            bodyFatBucket: $profile->bodyFatBucket, usesGLP1: $profile->usesGLP1, experience: $profile->experience,
        ));
        $readiness = PhaseReadiness::check(
            self::firstType($option), true, true, true,
            $this->intake->finalWeekFatG ?? INF, $this->intake->finalWeekTrainingCarbsG ?? INF, $lean,
        );
        foreach ($readiness->failures as $failure) {
            $warnings[] = match ($failure) {
                ReadinessFailure::FatTooLow => 'Fat is under '.PhaseReadiness::MINIMUM_FAT_GRAMS_PER_LB_LEAN.' g per lb of lean mass',
                ReadinessFailure::CarbsTooLow => 'Training-day carbs are under '.PhaseReadiness::minimumCarbGramsPerLbLean().' g per lb of lean mass',
                default => null,
            };
        }

        return array_values(array_filter($warnings));
    }

    /**
     * PH-046: the questions asked before a new Fat loss or Muscle gain, each with the warning a no gives; none for
     * Maintenance.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function readiness(NextPhaseOption $option): array
    {
        if (self::firstType($option) === PhaseType::Maintenance) {
            return [];
        }

        return [
            ['Is your diet fatigue low?', 'Diet fatigue is not low. Run Maintenance first.'],
            ['Is your training fatigue low?', 'Training fatigue is not low. Run Maintenance first.'],
            ['Is your calendar clear for the next phase?', 'Your calendar is not clear for the next phase. Run Maintenance first.'],
        ];
    }

    // MARK: shared TBL-010 warnings

    /** @return list<string> */
    public static function fatLossLengthWarnings(int $weeks): array
    {
        return array_values(array_filter([
            $weeks < TBL010Phases::MINI_CUT_SUGGESTED_BELOW_FAT_LOSS_WEEKS
                ? 'Under '.TBL010Phases::MINI_CUT_SUGGESTED_BELOW_FAT_LOSS_WEEKS.' weeks, a Mini-cut is a better fit than Fat loss.' : null,
            $weeks > TBL010Phases::FAT_LOSS_WARN_ABOVE_WEEKS
                ? 'Fat loss over '.TBL010Phases::FAT_LOSS_WARN_ABOVE_WEEKS.' weeks is a long diet. Consider a shorter phase.' : null,
        ]));
    }

    /** TBL-010: the planned loss over the counted weeks passes 10% of starting bodyweight. */
    public static function lossOverTenPercent(float $rate, int $countedWeeks): ?string
    {
        return abs($rate) * max(0, $countedWeeks) > TBL010Phases::MAX_FAT_LOSS_PERCENT_OF_START_BODYWEIGHT
            ? 'This phase plans to lose more than '.(int) TBL010Phases::MAX_FAT_LOSS_PERCENT_OF_START_BODYWEIGHT.'% of your starting bodyweight.' : null;
    }

    /** The profile as the energy and macro rules read it; null without the answers they need. */
    public function domainProfile(): ?DomainProfile
    {
        $p = $this->plan->profile;
        $sex = Sex::tryFrom((string) ($p->sex ?? ''));
        $bucket = BodyFatBucket::tryFrom((string) ($p->body_fat_bucket ?? ''));
        if ($p === null || $sex === null || $bucket === null || $p->getAttribute('birth_date') === null) {
            return null;
        }

        return new DomainProfile(
            name: (string) $p->name, sex: $sex, birthDate: PlanSnapshot::day($p->birth_date), heightCm: (float) $p->height_cm,
            weightKg: (float) $p->weight_kg, weightUnit: $this->plan->weightUnit(),
            heightUnit: HeightUnit::tryFrom((string) $p->height_unit) ?? HeightUnit::Cm, bodyFatBucket: $bucket,
            usesGLP1: (bool) $p->uses_glp1, experience: ExperienceLevel::tryFrom((string) $p->experience) ?? ExperienceLevel::Intermediate,
        );
    }
}
