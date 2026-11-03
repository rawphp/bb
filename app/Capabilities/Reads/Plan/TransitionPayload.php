<?php

namespace App\Capabilities\Reads\Plan;

use App\Capabilities\Plan\Data\PlanLengthChoice;
use App\Capabilities\Plan\Data\PlanReadinessQuestion;
use App\Capabilities\Plan\Data\PlanStepChoice;
use App\Capabilities\Plan\Data\PlanStepUp;
use App\Capabilities\Plan\Data\PlanStepUpWeek;
use App\Capabilities\Plan\Data\PlanTextLine;
use App\Capabilities\Plan\Data\PlanTransition;
use App\Capabilities\Plan\Data\PlanTransitionOption;
use App\Capabilities\Plan\Data\PlanTransitionTemplate;
use App\Capabilities\Support\Status;
use App\Capabilities\Support\Tone;
use App\Capabilities\Train\Data\Measure;
use App\Domain\Phases\CalorieStepUp;
use App\Domain\Phases\CalorieStepUpMode;
use App\Domain\Phases\MaintenanceHold;
use App\Domain\Phases\NextPhaseOption;
use App\Domain\Phases\PhaseTraining;
use App\Domain\Phases\StartingCaloriesSource;
use App\Support\Plan\PhaseTransitions;
use App\Support\Plan\PlanWords;

/**
 * The phase transition screen (PH-030 to PH-046, rp-clone's PhaseTransitionModel): how the ending phase went and what
 * may come next. Null while no transition is due.
 */
final class TransitionPayload
{
    public static function build(PhaseTransitions $transitions): ?PlanTransition
    {
        $phase = $transitions->due();
        if ($phase === null) {
            return null;
        }
        $plan = $transitions->plan;
        $summary = $transitions->summary();
        $nutrition = ! $plan->trainingOnly();
        $mesocycles = $summary->mesocyclesCompleted;

        return new PlanTransition(
            phase_id: (string) $phase->id,
            title: "End {$phase->type->title()}",
            length_text: PlanWords::weeks($summary->weeks),
            weight_change_text: $nutrition ? PlanWords::weightChange($summary->weightChangeKg(), $plan->weightUnit()) : null,
            rate_text: $nutrition ? sprintf('%+.2f%% a week', $summary->weeklyRatePercent()) : null,
            adherence_text: $nutrition ? ((int) round($summary->adherence * 100)).'%' : null,
            mesocycles_text: $mesocycles === 1 ? '1 mesocycle' : "{$mesocycles} mesocycles",
            next_from_text: 'What comes next, from '.PlanWords::day($transitions->start()),
            options: array_map(
                fn (NextPhaseOption $option, int $index) => self::option($transitions, $option, $index === 0),
                $options = $transitions->options(),
                array_keys($options),
            ),
        );
    }

    private static function option(PhaseTransitions $transitions, NextPhaseOption $option, bool $selected): PlanTransitionOption
    {
        $default = $transitions->defaultWeeks($option);
        $type = PhaseTransitions::firstType($option);
        $starting = $transitions->startingCalories();
        $kcal = $starting === null ? null : number_format(round($starting->kcal));

        return new PlanTransitionOption(
            choice: $type->value,
            gain_first: $option->isGainFirst(),
            title: $transitions->title($option),
            detail: $transitions->detail($option),
            is_recommended: $transitions->isRecommended($option),
            is_selected: $selected,
            lengths: array_map(fn (int $weeks) => self::length($transitions, $option, $weeks, $weeks === $default), $transitions->lengths($option)),
            note: $option->isGainFirst()
                ? 'A Muscle gain needs an end date here, so the last Muscle gain is saved at its shortest recommended length.'
                : null,
            starting_calories_text: match ($starting?->source) {
                null => null,
                StartingCaloriesSource::Measured => "Starts from your measured expenditure of {$kcal} kcal a day.",
                StartingCaloriesSource::Formula => "Starts from the formula estimate of {$kcal} kcal a day. There is no measured estimate to use yet.",
            },
            templates: array_map(
                fn (string $template, int $index) => self::template($option, $template, $index === 0),
                $templates = $transitions->templates($option),
                array_keys($templates),
            ),
            corridor_text: $transitions->hasStepUp($option) ? self::corridor($transitions) : null,
            step_up: $transitions->hasStepUp($option) ? self::stepUp($transitions, $option) : null,
            warnings: array_map(fn (string $text) => new Status($text, Tone::Warning->value), $transitions->optionWarnings($option)),
            readiness: array_map(fn (array $q) => new PlanReadinessQuestion($q[0], $q[1]), PhaseTransitions::readiness($option)),
            refusal: $transitions->refusal($option, $default),
        );
    }

    private static function length(PhaseTransitions $transitions, NextPhaseOption $option, int $weeks, bool $selected): PlanLengthChoice
    {
        return new PlanLengthChoice(
            weeks: $weeks,
            label: PlanWords::weeks($weeks),
            ends_text: 'Ends '.PlanWords::day($transitions->start()->modify('+'.(7 * $weeks - 1).' days')),
            is_selected: $selected,
            warnings: array_map(fn (string $text) => new Status($text, Tone::Warning->value), $transitions->lengthWarnings($option, $weeks)),
            mesocycles: [],
            fixes: [],
        );
    }

    private static function template(NextPhaseOption $option, string $template, bool $selected): PlanTransitionTemplate
    {
        $sessions = PhaseTraining::resensitizationSessionsPerWeek();
        $perMuscle = PhaseTraining::resensitizationSessionsPerMusclePerWeek();
        $weeks = PhaseTraining::resensitizationWeeks();

        return new PlanTransitionTemplate(
            template: $template,
            title: match ($template) {
                PhaseTransitions::RESENSITIZATION => 'Resensitization',
                PhaseTransitions::BALANCED => 'Balanced',
                default => PhaseTransitions::firstType($option)->title(),
            },
            detail: $template !== PhaseTransitions::RESENSITIZATION ? null
                : "A resensitization mesocycle: every muscle at Maintain, {$sessions->lower} to {$sessions->upper} full-body sessions a week, {$perMuscle->lower} to {$perMuscle->upper} sessions per muscle per week, near maintenance volume, {$weeks->lower} to {$weeks->upper} weeks.",
            training_lines: array_map(fn (string $line) => new PlanTextLine($line), PhaseTransitions::trainingLines($option, $template)),
            is_selected: $selected,
        );
    }

    /** PH-037: hold the new lower weight, not the pre-diet one. */
    private static function corridor(PhaseTransitions $transitions): string
    {
        $unit = $transitions->plan->weightUnit();
        $kg = $transitions->summary()->endTrendWeightKg;
        $corridor = MaintenanceHold::corridor($kg);

        return 'Hold your new weight of about '.PlanWords::weight($kg, $unit).', not the weight you started the diet at. Your trend stays between '
            .PlanWords::weight($corridor->lower, $unit).' and '.PlanWords::weight($corridor->upper, $unit).'.';
    }

    /** PH-044: each step size with its weekly targets over the longest Maintenance allowed. */
    private static function stepUp(PhaseTransitions $transitions, NextPhaseOption $option): PlanStepUp
    {
        $range = CalorieStepUp::stepRange();
        $last = $transitions->intake->finalWeekKcal;
        $maintenance = $transitions->startingCalories()?->kcal;
        $lengths = $transitions->lengths($option);
        $weeks = $lengths === [] ? 0 : max($lengths);

        return new PlanStepUp(
            explanation: 'After a diet, calories step back up by '.(int) $range->lower.' to '.(int) $range->upper.' a day and again every '
                .CalorieStepUp::WEEKS_PER_STEP.' weeks until you reach maintenance. '.CalorieStepUp::EXPLANATION.'. Choose to go straight to maintenance if you would rather not.',
            steps: array_map(fn (int $step, int $index) => new PlanStepChoice(
                step: new Measure((float) $step, 'kcal', "{$step} kcal"),
                is_selected: $index === 0,
                schedule: $last === null || $maintenance === null ? [] : array_map(
                    fn (int $week) => new PlanStepUpWeek('Week '.($week + 1), number_format(round(CalorieStepUp::targetKcal($week, $last, $maintenance, $step, CalorieStepUpMode::StepUp))).' kcal'),
                    $weeks === 0 ? [] : range(0, $weeks - 1),
                ),
            ), PhaseTransitions::steps(), array_keys(PhaseTransitions::steps())),
        );
    }
}
