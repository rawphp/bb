<?php

namespace App\Support\Plan;

use App\Actions\Settings\RecheckEligibility;
use App\Domain\Defaults\TBL010Phases;
use App\Domain\Defaults\TBL020MesocycleLength;
use App\Domain\Model\PhaseType;
use App\Domain\Phases\NextPhase;
use App\Domain\Phases\NextPhaseOption;
use App\Domain\Phases\PhaseTraining;
use App\Domain\Phases\PhaseWarnings;
use App\Domain\Plan\MesocycleAlignment;
use App\Domain\Plan\PhaseRefusal;
use App\Domain\Plan\PhaseRules;
use App\Domain\Plan\PlannedMesocycle;
use App\Domain\Safety\EligibilityMode;
use App\Domain\Safety\Screening;
use App\Models\Phase;
use App\Support\Settings\ProfileScreening;

/**
 * Phases added to the end of the plan (PLAN-006, PLAN-030): rp-clone's AddPhaseModel and the append point of its
 * PrebuiltPlans. `plan_show` lists what these allow and `phase_add` and `prebuilt_plan_apply` refuse what they refuse.
 */
final class NewPhases
{
    /** The longest phase offered (52 weeks), as Adjust phase length (PhaseChanges). */
    public const LONGEST = 52;

    /** PLAN-025: how far past its cap a cut may run on diet-break weeks, the most searched. */
    private const DIET_BREAK_SEARCH = 12;

    public const FAT_LOSS_AFTER_FAT_LOSS = 'Fat loss right after Fat loss is not recommended. Run a Maintenance phase between them.';

    public function __construct(public readonly PlanSnapshot $plan) {}

    // MARK: PLAN-006 where it goes

    public function last(): ?Phase
    {
        return $this->plan->phases->last();
    }

    /**
     * PLAN-006: the plan week a new phase starts on: where the last phase ends, or this week for an empty plan. Null
     * when the last phase has no end, so nothing can follow it.
     */
    public function startWeek(): ?int
    {
        $last = $this->last();
        if ($last === null) {
            return $this->plan->todayWeek ?? 0;
        }
        $range = $this->plan->weeksOf($last);

        return $range->upper === PlanSnapshot::OPEN ? null : $range->upper;
    }

    /** PLAN-006: why no phase can be added, or null. */
    public function refusal(): ?string
    {
        $last = $this->last();

        return $last !== null && $this->startWeek() === null ? PlanWords::refusal(PhaseRefusal::previousPhaseHasNoEnd(), $last->type) : null;
    }

    // MARK: PH-001 to PH-005 type, length and rate

    /**
     * PH-033, PH-034: what usually follows the last phase; Fat loss or Muscle gain for an empty plan. A cut never
     * follows a cut.
     *
     * @return list<PhaseType>
     */
    public function recommendedTypes(): array
    {
        $last = $this->last();
        if ($last === null) {
            return [PhaseType::FatLoss, PhaseType::MuscleGain];
        }

        return array_values(array_filter(array_map(fn (NextPhaseOption $option) => $option->phase, NextPhase::recommendation($last->type))));
    }

    /** The length a type opens on: one mesocycle in training-only mode (PLAN-007), else TBL-010's shortest recommended. */
    public function defaultWeeks(PhaseType $type): int
    {
        return self::openingWeeks($this->plan, $type);
    }

    public static function openingWeeks(PlanSnapshot $plan, PhaseType $type): int
    {
        if ($plan->trainingOnly()) {
            return TBL020MesocycleLength::row($plan->experience())->defaultWeeks;
        }
        $row = TBL010Phases::row($type);

        return match ($type) {
            PhaseType::MiniCut, PhaseType::Maintenance => 4,
            PhaseType::FatLoss, PhaseType::MuscleGain => (int) ($row->recommendedWeeks->lower ?? $row->minWeeks),
        };
    }

    /** PH-004: the rate a type opens on. */
    public function defaultRate(PhaseType $type): float
    {
        return PhaseRules::defaultRatePercentPerWeek($type, $this->plan->experience());
    }

    /**
     * TBL-010, PH-005: the lengths a new phase of the type may have, null for no end.
     *
     * @return list<?int>
     */
    public function lengths(PhaseType $type): array
    {
        return self::lengthsAt($this->plan, $type, (int) $this->startWeek());
    }

    /**
     * TBL-010, PH-005: the lengths a phase of the type starting at a plan week may have, up to 52 weeks, and null for
     * no end where the type allows it. A cut's diet-break weeks do not count towards its cap (PLAN-025), so it may run
     * longer.
     *
     * @return list<?int>
     */
    public static function lengthsAt(PlanSnapshot $plan, PhaseType $type, int $start): array
    {
        $row = TBL010Phases::row($type);
        $longest = $row->maxWeeks === null ? self::LONGEST
            : ($type->isFatLoss() && ! $plan->trainingOnly() ? $row->maxWeeks + self::DIET_BREAK_SEARCH : $row->maxWeeks);
        $lengths = [];
        foreach (range($row->minWeeks, $longest) as $weeks) {
            if (PhaseRules::validate($type, $weeks, null, self::dietBreaksAt($plan, $type, $start, $weeks)) === null) {
                $lengths[] = $weeks;
            }
        }
        if ($row->maxWeeks === null) {
            $lengths[] = null;
        }

        return $lengths;
    }

    /** PLAN-025: the deloads of the mesocycles proposed to fill a cut that fall inside it run at maintenance calories. */
    public function dietBreaks(PhaseType $type, ?int $weeks): int
    {
        $start = $this->startWeek();

        return $start === null ? 0 : self::dietBreaksAt($this->plan, $type, $start, $weeks);
    }

    public static function dietBreaksAt(PlanSnapshot $plan, PhaseType $type, int $start, ?int $weeks): int
    {
        if ($weeks === null || $weeks < 1 || ! $type->isFatLoss() || $plan->trainingOnly()) {
            return 0;
        }
        $mesocycles = MesocycleAlignment::mesocycles(MesocycleAlignment::proposal($type, $weeks, $plan->experience()), $start);

        return count(array_filter($mesocycles, fn (PlannedMesocycle $m) => $m->deloadWeek() < $start + $weeks));
    }

    /**
     * PLAN-020, PLAN-026: the mesocycles that would fill the phase from where it starts; one at a time for no end.
     *
     * @return list<PlannedMesocycle>
     */
    public function mesocycles(PhaseType $type, ?int $weeks): array
    {
        $start = $this->startWeek() ?? 0;
        $proposal = MesocycleAlignment::proposal($type, $weeks === null ? null : max($weeks, 1), $this->plan->experience());

        return MesocycleAlignment::mesocycles($proposal, $start);
    }

    /**
     * Why SAFE-001, PLAN-006 or TBL-010 refuses the phase, or null. `$rate` null in training-only mode is not checked
     * (PLAN-007).
     */
    public function check(PhaseType $type, ?int $weeks, ?float $rate): ?string
    {
        $refusal = $this->refusal() ?? self::screeningRefusal($this->plan, $type);
        if ($refusal !== null) {
            return $refusal;
        }
        if ($weeks !== null && $weeks > self::LONGEST) {
            return 'A phase can run at most '.self::LONGEST.' weeks.';
        }
        $refused = PhaseRules::validate($type, $weeks, $this->plan->trainingOnly() ? null : $rate, $this->dietBreaks($type, $weeks));

        return $refused === null ? null : PlanWords::refusal($refused, $type);
    }

    // MARK: SAFE-001

    /**
     * The types the user's screening allows: none that cuts while they are trying to conceive or in fertility
     * treatment (SAFE-001, PLAN-009). A training-only plan has no calories, so it allows every type.
     *
     * @return list<PhaseType>
     */
    public static function allowedTypes(PlanSnapshot $plan): array
    {
        $profile = $plan->profile;
        $blocked = $profile !== null && ! $plan->trainingOnly()
            && Screening::eligibilityFor(ProfileScreening::ticked($profile))->mode === EligibilityMode::FatLossBlocked;

        return array_values(array_filter(PhaseType::cases(), fn (PhaseType $type) => ! ($blocked && $type->isFatLoss())));
    }

    public static function screeningRefusal(PlanSnapshot $plan, PhaseType $type): ?string
    {
        return in_array($type, self::allowedTypes($plan), true) ? null : RecheckEligibility::FAT_LOSS_BLOCKED;
    }

    // MARK: PH-034, PH-038, TBL-010 warnings

    /**
     * PH-034, PH-038 and TBL-010 at a length and rate. They warn and never block.
     *
     * @return list<string>
     */
    public function warnings(PhaseType $type, ?int $weeks, float $rate): array
    {
        $warnings = [];
        $phases = $this->plan->phases->values();
        $last = $this->last();
        if ($type->isFatLoss() && $last !== null) {
            if ($last->type->isFatLoss()) {
                $warnings[] = self::FAT_LOSS_AFTER_FAT_LOSS;
            } elseif ($last->type === PhaseType::Maintenance && $phases->count() >= 2 && $phases[$phases->count() - 2]->type->isFatLoss()) {
                $short = PhaseWarnings::shortMaintenanceWarning(
                    $this->plan->weeksOf($last)->count(), $this->plan->weeksOf($phases[$phases->count() - 2])->count(),
                );
                if ($short !== null) {
                    $warnings[] = PhaseChanges::warningText($short);
                }
            }
        }
        if ($type === PhaseType::MiniCut && $last?->type === PhaseType::MuscleGain && $weeks !== null) {
            $ratio = PhaseWarnings::miniCutLength($this->plan->weeksOf($last)->count(), $weeks);
            if ($ratio !== null) {
                $warnings[] = PhaseChanges::warningText($ratio);
            }
        }
        if ($type === PhaseType::FatLoss && $weeks !== null) {
            array_push($warnings, ...self::fatLossLengthWarnings($weeks));
        }
        if ($type->isFatLoss() && ! $this->plan->trainingOnly() && $weeks !== null) {
            $loss = self::lossOverTenPercent($rate, $weeks - $this->dietBreaks($type, $weeks));
            if ($loss !== null) {
                $warnings[] = $loss;
            }
        }

        return $warnings;
    }

    // MARK: PH-008, PH-036, PH-039 training

    /** @return list<string> */
    public function trainingLines(PhaseType $type): array
    {
        $resensitization = $type === PhaseType::Maintenance && PhaseTraining::maintenanceDefaultsToResensitization($this->last()?->type);

        return PlanWords::trainingLines(PhaseTraining::mesocycleDefaults($type, $resensitization)->row);
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
}
