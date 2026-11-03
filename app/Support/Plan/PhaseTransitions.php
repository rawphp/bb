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
use App\Domain\Phases\CalorieStepUp;
use App\Domain\Phases\MaintenanceLength;
use App\Domain\Phases\NextPhase;
use App\Domain\Phases\NextPhaseOption;
use App\Domain\Phases\PhaseReadiness;
use App\Domain\Phases\PhaseSummary;
use App\Domain\Phases\PhaseTraining;
use App\Domain\Phases\PhaseWarnings;
use App\Domain\Phases\ReadinessFailure;
use App\Domain\Phases\StartingCalories;
use App\Domain\Plan\PhaseRules;
use App\Domain\Review\StepPlanUpdate;
use App\Models\Phase;
use App\Support\Today\TodaySnapshot;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The phase transition (PH-030 to PH-046): rp-clone's PhaseTransitionModel. A transition is due once the last phase
 * of the plan is in its final week or over, so it opens when the phase reaches its end and when `phase_end` ends it
 * at the next boundary (PH-007). Nothing is due while a phase follows it: what comes next is already saved.
 * Options the screening forbids are left out (SAFE-001).
 * `plan_show` lists what these offer and `phase_transition_complete` refuses what they do not.
 */
final class PhaseTransitions
{
    public const RESENSITIZATION = 'resensitization';

    public const BALANCED = 'balanced';

    public const STANDARD = 'standard';

    private ?Phase $due;

    private int $endWeek = 0;

    private ?PhaseSummary $summary = null;

    private ?StartingCalories $startingCalories = null;

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
        return $this->summary ??= $this->makeSummary();
    }

    private function makeSummary(): PhaseSummary
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
     * The recommendation first (the first is the default), then every other phase type the screening allows: none
     * that cuts while the user is trying to conceive (SAFE-001).
     *
     * @return list<NextPhaseOption>
     */
    public function options(): array
    {
        $transition = NextPhase::transition($this->summary());
        $allowed = NewPhases::allowedTypes($this->plan);

        return array_values(array_filter(
            [...$transition->recommendation, ...array_map(fn (PhaseType $type) => NextPhaseOption::phase($type), $transition->alternatives)],
            fn (NextPhaseOption $option) => in_array(self::firstType($option), $allowed, true),
        ));
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

    /**
     * The length the option opens on, one it offers: PH-031's whole mesocycles for Maintenance after a cut, else what a
     * new phase of the type opens on (one mesocycle in training-only mode). Null for Gain first, whose phases have
     * their own.
     */
    public function defaultWeeks(NextPhaseOption $option): ?int
    {
        if ($option->isGainFirst()) {
            return null;
        }
        $weeks = $this->plan->trainingOnly() ? null : $this->maintenanceLength($option)?->defaultWeeks;
        $weeks ??= NewPhases::openingWeeks($this->plan, self::firstType($option));
        $lengths = $this->lengths($option);

        return $lengths === [] || in_array($weeks, $lengths, true) ? $weeks : min(max($weeks, $lengths[0]), $lengths[count($lengths) - 1]);
    }

    /**
     * The lengths the option's phase may have (PH-031 after a cut, PH-033 after a gain, TBL-010 and PLAN-025 from where
     * it starts); none for Gain first. Every phase here gets an end.
     *
     * @return list<int>
     */
    public function lengths(NextPhaseOption $option): array
    {
        if ($option->isGainFirst()) {
            return [];
        }
        $type = self::firstType($option);
        $range = $this->maintenanceLength($option)->allowedWeeks
            ?? ($type === PhaseType::Maintenance && $this->endingType() === PhaseType::MuscleGain ? NextPhase::maintenanceAfterGainWeeks() : null);

        return array_values(array_filter(
            NewPhases::lengthsAt($this->plan, $type, $this->endWeek),
            fn (?int $weeks) => $weeks !== null && ($range === null || $range->contains($weeks)),
        ));
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
                NewPhases::dietBreaksAt($this->plan, $phase['type'], $phase['start_week'], $phase['weeks']),
            );
            if ($refused !== null) {
                return PlanWords::refusal($refused, $phase['type']);
            }
        }

        return null;
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
            ? [self::RESENSITIZATION, self::BALANCED] : [self::STANDARD];
    }

    /** @return list<string> */
    public static function trainingLines(NextPhaseOption $option, string $template): array
    {
        return PlanWords::trainingLines(PhaseTraining::mesocycleDefaults(self::firstType($option), $template === self::RESENSITIZATION)->row);
    }

    // MARK: PH-035 starting calories

    /** Measured expenditure (REV-021) is not read on the server yet, so the formula (NUT-002) stands in. Null in training-only mode. */
    public function startingCalories(): ?StartingCalories
    {
        $profile = $this->domainProfile();
        if ($profile === null || $this->plan->trainingOnly()) {
            return null;
        }
        if ($this->startingCalories !== null) {
            return $this->startingCalories;
        }
        $summary = $this->summary();
        $bmr = EnergyRules::bmr($profile, $summary->endTrendWeightKg, $this->plan->today, new DateTimeZone('UTC'));
        $steps = [];
        foreach (TodaySnapshot::json($this->plan->profile?->step_plan) as $day) {
            $day = TodaySnapshot::json($day);
            $steps[] = new StepPlanDay((int) ($day['weekday'] ?? 0), (int) ($day['min'] ?? 0), (int) ($day['max'] ?? 0));
        }
        $formula = EnergyRules::dailyExpenditure($bmr, EnergyRules::activityFactor(StepPlanUpdate::plannedDailySteps($steps)), 0);

        return $this->startingCalories = StartingCalories::resolve(null, $summary->weeks, false, $formula);
    }

    // MARK: PH-044 step up

    /** Only for Maintenance after Fat loss with nutrition on. */
    public function hasStepUp(NextPhaseOption $option): bool
    {
        return $this->isMaintenanceAfterFatLoss($option) && ! $this->plan->trainingOnly();
    }

    /** @return list<int> the step sizes offered, kcal a day: PH-044's smallest and largest */
    public static function steps(): array
    {
        $range = CalorieStepUp::stepRange();

        return [(int) $range->lower, (int) $range->upper];
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
                $warnings[] = NewPhases::FAT_LOSS_AFTER_FAT_LOSS;
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
            array_push($warnings, ...NewPhases::fatLossLengthWarnings($weeks));
            $loss = $this->plan->trainingOnly() ? null
                : NewPhases::lossOverTenPercent($this->rate($type), $weeks - NewPhases::dietBreaksAt($this->plan, $type, $this->endWeek, $weeks));
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
