<?php

namespace App\Capabilities\Reads\Plan;

use App\Capabilities\Plan\Data\PlanAddPhaseType;
use App\Capabilities\Plan\Data\PlanLengthChoice;
use App\Capabilities\Plan\Data\PlanPrebuilt;
use App\Capabilities\Plan\Data\PlanPrebuiltPhase;
use App\Capabilities\Plan\Data\PlanPrebuiltSegment;
use App\Capabilities\Plan\Data\PlanProposedMesocycle;
use App\Capabilities\Plan\Data\PlanRateChoice;
use App\Capabilities\Plan\Data\PlanTextLine;
use App\Capabilities\Support\Tone;
use App\Capabilities\Train\Data\Measure;
use App\Capabilities\Train\Data\Pill;
use App\Domain\Defaults\TBL010Phases;
use App\Domain\Defaults\TBL010Phases\RatePreset;
use App\Domain\Model\PhaseType;
use App\Domain\Plan\PlannedMesocycle;
use App\Domain\Plan\PrebuiltPlan;
use App\Support\Plan\NewPhases;
use App\Support\Plan\PhaseChanges;
use App\Support\Plan\PlanSnapshot;
use App\Support\Plan\PlanWords;

/**
 * Add a phase (PLAN-006, PH-001 to PH-005, PH-033, PH-034, PH-038) and RP's prebuilt plans (PLAN-030), from rp-clone's
 * AddPhaseModel and PrebuiltPlans: what `plan_show` offers at the end of the plan.
 */
final class NewPhasePayload
{
    public const RATE_UNIT = '%/wk';

    /**
     * Each type the form offers, or none when nothing can follow the last phase.
     *
     * @return list<PlanAddPhaseType>
     */
    public static function types(NewPhases $new): array
    {
        if ($new->refusal() !== null) {
            return [];
        }
        $recommended = $new->recommendedTypes();
        $allowed = NewPhases::allowedTypes($new->plan);

        return array_values(array_map(
            fn (PhaseType $type) => self::type($new, $type, in_array($type, $recommended, true)),
            array_filter(PhaseChanges::TYPES, fn (PhaseType $type) => in_array($type, $allowed, true)),
        ));
    }

    private static function type(NewPhases $new, PhaseType $type, bool $recommended): PlanAddPhaseType
    {
        $trainingOnly = $new->plan->trainingOnly();
        $row = TBL010Phases::row($type);
        $rate = $new->defaultRate($type);
        $default = $new->defaultWeeks($type);
        $lengths = $new->lengths($type);
        $selected = in_array($default, $lengths, true) ? $default : ($lengths[0] ?? null);
        $custom = ! $trainingOnly && self::hasCustomRate($type);

        return new PlanAddPhaseType(
            type: $type->value,
            title: $type->title(),
            is_recommended: $recommended,
            lengths: array_map(fn (?int $weeks) => self::length($new, $type, $weeks, $rate, $weeks === $selected), $lengths),
            rates: $trainingOnly ? [] : self::rates($type, $rate),
            custom_rate_min: $custom ? self::rate($row->rateRangePercentPerWeek->lower) : null,
            custom_rate_max: $custom ? self::rate($row->rateRangePercentPerWeek->upper) : null,
            training_lines: array_map(fn (string $line) => new PlanTextLine($line), $new->trainingLines($type)),
        );
    }

    /** TBL-010's Custom rate, and PH-004's small gain or loss around Maintenance's hold. */
    private static function hasCustomRate(PhaseType $type): bool
    {
        $presets = TBL010Phases::row($type)->presets;

        return $type === PhaseType::Maintenance || in_array(null, array_map(fn (RatePreset $p) => $p->ratePercentPerWeek, $presets), true);
    }

    /**
     * The TBL-010 presets with a rate; Muscle gain's is the one its experience recommends (PH-004).
     *
     * @return list<PlanRateChoice>
     */
    private static function rates(PhaseType $type, float $default): array
    {
        $presets = array_values(array_filter(TBL010Phases::row($type)->presets, fn (RatePreset $p) => $p->ratePercentPerWeek !== null));
        if ($presets === []) {
            $presets = [new RatePreset('Recommended', $default)];
        }

        return array_map(fn (RatePreset $p) => new PlanRateChoice($p->name, self::rate((float) $p->ratePercentPerWeek), $p->ratePercentPerWeek == $default), $presets);
    }

    private static function length(NewPhases $new, PhaseType $type, ?int $weeks, float $rate, bool $selected): PlanLengthChoice
    {
        $start = (int) $new->startWeek();

        return new PlanLengthChoice(
            weeks: $weeks,
            label: $weeks === null ? 'No end' : PlanWords::weeks($weeks),
            ends_text: $weeks === null ? 'No end date' : 'Ends '.PlanWords::day($new->plan->dayOfWeek($start + $weeks)->modify('-1 day')),
            is_selected: $selected,
            warnings: array_map(fn (string $text) => new Pill($text, Tone::Warning->value), $new->warnings($type, $weeks, $rate)),
            mesocycles: array_map(fn (PlannedMesocycle $m, int $i) => self::mesocycle($new->plan, $m, $i), $mesocycles = $new->mesocycles($type, $weeks), array_keys($mesocycles)),
            fixes: [],
        );
    }

    private static function mesocycle(PlanSnapshot $plan, PlannedMesocycle $mesocycle, int $index): PlanProposedMesocycle
    {
        return new PlanProposedMesocycle(
            label: 'Mesocycle '.($index + 1),
            weeks_text: PlanWords::weeks($mesocycle->weeks),
            dates_text: PlanWords::dates($plan->dayOfWeek($mesocycle->startWeek), $plan->dayOfWeek($mesocycle->nextStartWeek())),
        );
    }

    /** "Lose 0.75% a week" as a Measure. */
    public static function rate(float $rate): Measure
    {
        return new Measure($rate, self::RATE_UNIT, PlanWords::rate($rate));
    }

    // MARK: PLAN-030

    /**
     * Both prebuilt plans laid out from where the plan ends; none for a user without a plan or when the last phase
     * has no end (PLAN-006). Both hold a Fat loss, so none while the screening blocks one (SAFE-001).
     *
     * @return list<PlanPrebuilt>
     */
    public static function prebuilt(NewPhases $new): array
    {
        $start = $new->startWeek();
        if ($start === null || NewPhases::screeningRefusal($new->plan, PhaseType::FatLoss) !== null) {
            return [];
        }

        return array_map(fn (PrebuiltPlan $plan) => self::prebuiltPlan($new->plan, $plan, $start), PrebuiltPlan::cases());
    }

    private static function prebuiltPlan(PlanSnapshot $plan, PrebuiltPlan $prebuilt, int $start): PlanPrebuilt
    {
        $layout = $prebuilt->layout($start, $plan->grid, fn () => '');
        $segments = [];
        foreach ($layout->mesocycles as $i => $m) {
            $segments[] = new PlanPrebuiltSegment('mesocycle', 'Mesocycle '.($i + 1), $m->startWeek - $start, $m->weeks);
        }
        if ($layout->activeRestWeeks !== []) {
            $segments[] = new PlanPrebuiltSegment('active_rest', 'Active rest', min($layout->activeRestWeeks) - $start, count($layout->activeRestWeeks));
        }
        usort($segments, fn (PlanPrebuiltSegment $a, PlanPrebuiltSegment $b) => [$a->start_week, $a->kind === 'active_rest'] <=> [$b->start_week, $b->kind === 'active_rest']);

        return new PlanPrebuilt(
            plan: $prebuilt->value,
            title: $prebuilt === PrebuiltPlan::MuscleGain ? 'Muscle gain plan' : 'Fat loss plan',
            summary: PrebuiltPlan::LENGTH_IN_WEEKS." weeks from RP's 6-month guide",
            starts_text: 'Starts '.PlanWords::day($plan->dayOfWeek($start)),
            refusal: null,
            phases: array_map(fn ($phase) => new PlanPrebuiltPhase(
                type: $phase->type->value,
                title: $phase->type->title(),
                weeks_text: PlanWords::weeks((int) $plan->grid->weekIndexContaining($phase->end) - (int) $plan->grid->weekIndexContaining($phase->start)),
                dates_text: PlanWords::dates($phase->start, $phase->end),
                rate_text: $plan->trainingOnly() ? null : PlanWords::rate($phase->targetRatePercentPerWeek),
            ), $layout->phases),
            segments: $segments,
        );
    }
}
