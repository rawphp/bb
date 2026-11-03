<?php

namespace App\Capabilities\Reads\Review;

use App\Capabilities\Reads\Data\Today\Status;
use App\Capabilities\Review\Data\ReviewCalories;
use App\Capabilities\Review\Data\ReviewChoice;
use App\Capabilities\Review\Data\ReviewDeload;
use App\Capabilities\Review\Data\ReviewOwnCalories;
use App\Capabilities\Review\Data\ReviewStepOffer;
use App\Capabilities\Support\Line;
use App\Capabilities\Support\Tone;
use App\Domain\Review\BuiltWeekSummary;
use App\Domain\Review\CalorieOptionKind;
use App\Domain\Review\CalorieOptions;
use App\Domain\Review\DeloadMove\PlanKind;
use App\Domain\Review\RecommendationReasonKind;
use App\Domain\Review\StallOptions;
use App\Domain\WeekBuilder\BuiltWeek;
use App\Domain\WeekBuilder\WeekBuildRefusal;
use App\Support\Review\ReviewAnalysis;
use App\Support\Review\ReviewAnswers;
use App\Support\Review\ReviewMessages;
use LogicException;

/** Step 6 of the weekly review: the calorie options (REV-010 to REV-013) with the reasons the rules give. */
final class ReviewCaloriesPayload
{
    private const DROP_FATIGUE_DETAIL = 'Take a break. Eating closer to maintenance will drop fatigue and may let you judge your progress better';

    private const CHOOSE_OWN_DETAIL = 'Average daily calories, within safety limits';

    /** Null outside a full review. `$choices` lets the preview builder share the choices (and their built weeks). */
    public static function build(ReviewAnalysis $analysis, ReviewAnswers $answers, ?ReviewChoices $choices = null): ?ReviewCalories
    {
        $choices ??= new ReviewChoices($analysis, $answers);
        $layout = $choices->layout();
        $kinds = $analysis->options?->kinds;
        if ($layout === null || $kinds === null) {
            return null;
        }
        $selected = $choices->selected();
        $reviewed = $analysis->reviewedWeek();

        return new ReviewCalories(
            lines: [
                new Line('Last week\'s target', ReviewWords::kcal($reviewed->targetKcal ?? self::plannedAverage($analysis) ?? 0), Tone::Neutral->value),
                new Line('Last week\'s average', ($average = $reviewed?->averageIntakeKcal()) === null ? 'Nothing checked in' : ReviewWords::kcal($average), Tone::Neutral->value),
            ],
            notes: self::notes($analysis, $kinds),
            options: array_map(
                fn (string $id) => self::option($choices, $id, $layout, $selected),
                $layout['ids'],
            ),
            own: $selected === 'choose_own'
                ? new ReviewOwnCalories('Calories a day', 'kcal a day', ($own = $choices->ownKcal()) === null ? null : (int) round($own))
                : null,
            deload: $selected === 'drop_fatigue' ? self::deload($choices) : null,
            step_offer: $analysis->stepOffer === null ? null : new ReviewStepOffer(
                'Update my step plan to '.ReviewMessages::number((int) round($analysis->stepOffer->actualAverage)).' steps a day',
                $answers->updateStepPlan,
            ),
        );
    }

    /**
     * @param  array{ids: list<string>, stall: ?StallOptions, recommended: ?string}  $layout
     */
    private static function option(ReviewChoices $choices, string $id, array $layout, ?string $selected): ReviewChoice
    {
        $week = $choices->week($id);
        $lines = [];
        $refusal = null;
        if ($week instanceof WeekBuildRefusal) {
            $refusal = ReviewMessages::refusal($week);
        } elseif ($week === null && $id === 'choose_own') {
            $refusal = 'Enter the average calories a day.';
        }
        if ($refusal !== null) {
            $lines[] = $refusal;
        } elseif ($week instanceof BuiltWeek) {
            $lines[] = self::nextWeek($week);
        }
        $words = match ($id) {
            'drop_fatigue' => self::DROP_FATIGUE_DETAIL,
            'choose_own' => self::CHOOSE_OWN_DETAIL,
            default => null,
        };
        if ($words !== null) {
            $lines[] = $words;
        }

        return new ReviewChoice(
            id: $id,
            title: self::title($id, $layout['stall']),
            detail: implode("\n", array_filter($lines, fn (string $line) => $line !== '')),
            is_recommended: $id === $layout['recommended'],
            is_selected: $id === $selected,
            is_enabled: $refusal === null,
        );
    }

    /** REV-013: next week's average calories and macro split. */
    private static function nextWeek(BuiltWeek $week): string
    {
        $values = [
            BuiltWeekSummary::averageKcal($week), BuiltWeekSummary::averageProteinG($week),
            BuiltWeekSummary::averageFatG($week), BuiltWeekSummary::averageCarbsG($week),
        ];
        if (in_array(null, $values, true)) {
            return '';
        }
        [$kcal, $protein, $fat, $carbs] = array_map(fn (float $value) => (int) round($value), $values);

        return "Next week: {$kcal} kcal · P {$protein} · F {$fat} · C {$carbs}";
    }

    private static function title(string $id, ?StallOptions $stall): string
    {
        return match ($id) {
            'recommendation' => 'Take the recommendation',
            'repeat_this_week' => $stall === null ? 'Repeat this week' : 'Hold for another week',
            'choose_own' => 'Choose my own calories',
            'drop_fatigue' => $stall === null ? 'Drop fatigue' : 'Take a diet break',
            'cut_calories' => 'Cut '.ReviewMessages::kcal($stall->cutKcal ?? 0).' calories a day',
            'add_steps' => 'Add '.ReviewMessages::number($stall->addSteps ?? 0).' steps a day',
            default => throw new LogicException("No title for the calorie option {$id}."),
        };
    }

    /**
     * REV-011: how Drop fatigue meets the deload.
     */
    private static function deload(ReviewChoices $choices): ?ReviewDeload
    {
        $plan = $choices->analysis->deload;
        $rir = fn () => $plan->move === null || $plan->move->rirByWeek() === [] ? null : 'Target RIR by week: '.implode(', ', $plan->move->rirByWeek());

        return match ($plan->kind) {
            PlanKind::DeloadNow => new ReviewDeload(
                'deload_now', null, null, 'Deload now: the diet break and the deload are one week, starting next week.', $rir(),
            ),
            PlanKind::AskDeloadToo => new ReviewDeload(
                'ask', 'Make this your deload week too?', $choices->deloadAnswer(), null, $choices->deloadAnswer() === true ? $rir() : null,
            ),
            PlanKind::DietBreakOnly => null,
        };
    }

    /**
     * What the step says beside the options, in order: REV-012, the reason for the recommendation, fatigue (REV-040 to
     * REV-043) and the step plan offer (REV-050).
     *
     * @param  list<CalorieOptionKind>  $kinds
     * @return list<Status>
     */
    private static function notes(ReviewAnalysis $analysis, array $kinds): array
    {
        $notes = [];
        if ($analysis->options?->nextIsDietBreak === true) {
            $notes[] = new Status(CalorieOptions::DIET_BREAK_MESSAGE, Tone::Info->value);
        }
        $recommendation = $analysis->recommendation;
        if ($recommendation !== null && in_array(CalorieOptionKind::Recommendation, $kinds, true)) {
            $warns = in_array($recommendation->reason->kind, [RecommendationReasonKind::Stalled, RecommendationReasonKind::WaterWeight], true);
            $notes[] = new Status(
                ReviewMessages::reason($recommendation->reason, $analysis->reviewedWeek()->targetKcal ?? 0),
                ($warns ? Tone::Warning : Tone::Info)->value,
            );
        }
        if ($analysis->fatigue !== null) {
            $notes[] = new Status(ReviewMessages::fatigue($analysis->fatigue, $analysis->weeksSinceBreak), Tone::Warning->value);
        }
        if ($analysis->stepOffer !== null) {
            $notes[] = new Status(ReviewMessages::steps($analysis->stepOffer), Tone::Info->value);
        }

        return $notes;
    }

    private static function plannedAverage(ReviewAnalysis $analysis): ?float
    {
        return $analysis->plannedNextWeek === null ? null : BuiltWeekSummary::averageKcal($analysis->plannedNextWeek);
    }
}
