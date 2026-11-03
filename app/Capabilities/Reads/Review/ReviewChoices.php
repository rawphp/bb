<?php

namespace App\Capabilities\Reads\Review;

use App\Domain\Model\Mesocycle;
use App\Domain\Review\BuiltWeekSummary;
use App\Domain\Review\CalorieOptionKind;
use App\Domain\Review\DeloadMove;
use App\Domain\Review\DeloadMove\PlanKind;
use App\Domain\Review\NextWeekCalories;
use App\Domain\Review\RecommendationReasonKind;
use App\Domain\Review\ReviewMode;
use App\Domain\Review\ReviewNextWeek;
use App\Domain\Review\ReviewWorld;
use App\Domain\Review\StallChange;
use App\Domain\Review\StallOptions;
use App\Domain\WeekBuilder\BuiltWeek;
use App\Domain\WeekBuilder\WeekBuildRefusal;
use App\Support\Review\ReviewAnalysis;
use App\Support\Review\ReviewAnswers;

/**
 * The calorie step's choices for one set of answers (rp-clone WeeklyReviewModel+Calories): which options it lists, which
 * one is recommended and which selected, and the calories and world each option stands for. The calories builder and
 * the preview builder both read it, so selection, deload and own-amount logic live here once.
 *
 * Option ids are the wire ids: `recommendation`, `repeat_this_week`, `choose_own`, `drop_fatigue`, and on a stall
 * `cut_calories` and `add_steps`.
 */
final class ReviewChoices
{
    /** @var array<string, BuiltWeek|WeekBuildRefusal|null> next week as each option builds it */
    private array $weeks = [];

    public function __construct(public readonly ReviewAnalysis $analysis, public readonly ReviewAnswers $answers) {}

    /**
     * Which options the step lists, in order, and which one is recommended. Nothing is built for this. Null outside a
     * full review.
     *
     * @return ?array{ids: list<string>, stall: ?StallOptions, recommended: ?string}
     */
    public function layout(): ?array
    {
        $analysis = $this->analysis;
        if ($analysis->mode !== ReviewMode::Full || $analysis->options === null) {
            return null;
        }
        $kinds = $analysis->options->kinds;
        $reason = $analysis->recommendation?->reason;
        $stall = $reason?->kind === RecommendationReasonKind::Stalled && in_array(CalorieOptionKind::Recommendation, $kinds, true)
            ? $reason->stall
            : null;
        if ($stall !== null) {
            // REV-027: a stall swaps the recommendation for its own changes.
            $ids = [];
            if ($stall->cutKcal !== null) {
                $ids[] = 'cut_calories';
            }
            if ($stall->addSteps !== null) {
                $ids[] = 'add_steps';
            }
            $ids[] = 'repeat_this_week';
            if (in_array(CalorieOptionKind::DropFatigue, $kinds, true)) {
                $ids[] = 'drop_fatigue';
            }
            $ids[] = 'choose_own';
        } else {
            $ids = array_map(fn (CalorieOptionKind $kind) => match ($kind) {
                CalorieOptionKind::Recommendation => 'recommendation',
                CalorieOptionKind::RepeatThisWeek => 'repeat_this_week',
                CalorieOptionKind::ChooseOwn => 'choose_own',
                CalorieOptionKind::DropFatigue => 'drop_fatigue',
            }, $kinds);
        }
        $recommended = null;
        if ($analysis->fatigue?->dropFatigue !== null && in_array('drop_fatigue', $ids, true)) {
            $recommended = 'drop_fatigue';
        } elseif ($stall !== null) {
            $recommended = match ($stall->recommended) {
                StallChange::CutCalories => 'cut_calories',
                StallChange::AddSteps => 'add_steps',
                StallChange::Hold => 'repeat_this_week',
            };
        } elseif (in_array('recommendation', $ids, true)) {
            $recommended = 'recommendation';
        }

        return ['ids' => $ids, 'stall' => $stall, 'recommended' => in_array($recommended, $ids, true) ? $recommended : null];
    }

    /**
     * The option selected. Nothing answered yet selects the recommended one, or the first listed; once answers exist, the
     * answered option does, when the layout lists it.
     */
    public function selected(): ?string
    {
        $layout = $this->layout();
        if ($layout === null) {
            return null;
        }
        $fallback = $layout['recommended'] ?? $layout['ids'][0] ?? null;
        if ($this->answers == ReviewAnswers::defaults()) {
            return $fallback;
        }

        return in_array($this->answers->option, $layout['ids'], true) ? $this->answers->option : $fallback;
    }

    /** The average calories "Choose my own" stands for: the amount answered, else the suggested one; null when there is none. */
    public function ownKcal(): ?float
    {
        $analysis = $this->analysis;
        $basis = $analysis->recommendation->kcal ?? $analysis->repeatKcal
            ?? ($analysis->plannedNextWeek === null ? null : BuiltWeekSummary::averageKcal($analysis->plannedNextWeek)) ?? 0;
        $kcal = $this->answers->ownKcal ?? round($basis);

        return $kcal > 0 ? (float) $kcal : null;
    }

    /** REV-011: the answer to "Make this your deload week too?" that applies; null when nothing is asked. */
    public function deloadAnswer(): ?bool
    {
        $deload = $this->analysis->deload;
        if ($deload->kind !== PlanKind::AskDeloadToo) {
            return null;
        }

        return $this->answers->deloadToo ?? ($this->selected() === 'drop_fatigue' ? $deload->defaultYes : null);
    }

    /** REV-011: the mesocycle shortened so its deload lands on next week, when Drop fatigue is chosen with it. */
    public function movedDeload(): ?DeloadMove
    {
        $deload = $this->analysis->deload;

        return match ($deload->kind) {
            PlanKind::DeloadNow => $deload->move,
            PlanKind::AskDeloadToo => $this->deloadAnswer() === true ? $deload->move : null,
            PlanKind::DietBreakOnly => null,
        };
    }

    /** REV-002: a planned week keeps its own rules; otherwise the calories to repeat. */
    public function repeatCalories(): NextWeekCalories
    {
        $planned = $this->analysis->plannedNextWeek;
        if ($planned !== null && ReviewNextWeek::isPlanned($planned->week)) {
            return NextWeekCalories::planned();
        }

        return $this->analysis->repeatKcal === null ? NextWeekCalories::planned() : NextWeekCalories::average($this->analysis->repeatKcal);
    }

    /**
     * The calorie choice and world an option stands for; null when it stands for none (no amount typed, or no stall to
     * cut from).
     *
     * @return ?array{calories: NextWeekCalories, world: ReviewWorld}
     */
    public function choice(string $id): ?array
    {
        $analysis = $this->analysis;
        $world = $analysis->world;
        if ($world === null) {
            return null;
        }
        $last = $analysis->weeks === [] ? null : $analysis->weeks[count($analysis->weeks) - 1];
        $current = $last !== null && $last->week->start == $analysis->reviewed->start ? $last->targetKcal : null;
        $reason = $analysis->recommendation?->reason;

        return match ($id) {
            'recommendation' => ['calories' => $analysis->recommendation === null ? NextWeekCalories::planned() : NextWeekCalories::average($analysis->recommendation->kcal), 'world' => $world],
            'repeat_this_week' => ['calories' => $this->repeatCalories(), 'world' => $world],
            'choose_own' => ($own = $this->ownKcal()) === null ? null : ['calories' => NextWeekCalories::average($own), 'world' => $world],
            'drop_fatigue' => ['calories' => NextWeekCalories::dietBreak(), 'world' => self::withMesocycleWeeks($world, $this->movedDeload())],
            'cut_calories' => $current === null || $reason?->kind !== RecommendationReasonKind::Stalled || $reason->stall?->cutKcal === null
                ? null
                : ['calories' => NextWeekCalories::average($current - $reason->stall->cutKcal), 'world' => $world],
            'add_steps' => $current === null ? null : ['calories' => NextWeekCalories::average($current), 'world' => $world],
            default => null,
        };
    }

    /**
     * Next week as an option builds it, once; the week's refusal when the rules refuse it; null when the option stands
     * for no calories.
     */
    public function week(string $id): BuiltWeek|WeekBuildRefusal|null
    {
        if (array_key_exists($id, $this->weeks)) {
            return $this->weeks[$id];
        }
        $choice = $this->choice($id);
        if ($choice === null) {
            return $this->weeks[$id] = null;
        }
        try {
            $week = $id === 'repeat_this_week'
                // REV-002 gives the same answer as the calories do for a planned week.
                ? ReviewNextWeek::repeatWeek($choice['world'], $this->analysis->repeatKcal, $this->analysis->plannedNextWeek)
                : ReviewNextWeek::build($choice['world'], $choice['calories'], [], $this->analysis->plannedNextWeek);
        } catch (WeekBuildRefusal $refusal) {
            $week = $refusal;
        }

        return $this->weeks[$id] = $week;
    }

    /** The world with its mesocycle shortened to the deload move (REV-011); as it was without one. */
    private static function withMesocycleWeeks(ReviewWorld $world, ?DeloadMove $move): ReviewWorld
    {
        if ($move === null) {
            return $world;
        }
        $m = $world->mesocycle;

        return new ReviewWorld(
            plan: $world->plan,
            profile: $world->profile,
            schedule: $world->schedule,
            mesocycle: new Mesocycle(
                $m->id, $m->phase, $move->weeks, $m->sessions, $m->musclePriorities, $m->isResensitization, $m->earlierWeeks, $m->laterWeeks, $m->lastDay,
            ),
            mesocycleStartWeek: $world->mesocycleStartWeek,
            nextWeek: $world->nextWeek,
            newId: $world->newId,
            away: $world->away,
            storedDietBreak: $world->storedDietBreak,
            storedDeload: $world->storedDeload,
            dietBreakTurnedOff: $world->dietBreakTurnedOff,
            pausedWeeks: $world->pausedWeeks,
            otherActivities: $world->otherActivities,
        );
    }
}
