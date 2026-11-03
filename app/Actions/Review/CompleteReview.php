<?php

namespace App\Actions\Review;

use App\Domain\Model\StepPlanDay;
use App\Domain\Review\ReviewMode;
use App\Domain\Review\StepPlanUpdate;
use App\Domain\WeekBuilder\BuiltWeek;
use App\Domain\WeekBuilder\WeekBuildRefusal;
use App\Domain\WeekBuilder\WeekDay;
use App\Models\DayTarget;
use App\Models\Mesocycle;
use App\Models\PlanWeek;
use App\Models\Profile;
use App\Models\ReviewAnswer;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Plan\DayTargetRow;
use App\Support\Review\ReviewAnalysis;
use App\Support\Review\ReviewAnswers;
use App\Support\Review\ReviewChoices;
use App\Support\Review\ReviewMessages;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Saves the weekly review (REV-001, rp-clone WeeklyReviewModel+Save): applies the week's stored answers, or the
 * unanswered defaults, and writes in one transaction next week's plan week and days, the diet break and the moved
 * deload (REV-011), the step plan (REV-027, REV-050) and, last, the reviewed week's stamp. Nothing before it applies
 * an answer (REV-031). Choose my own with no amount yet is refused (REV-010), and so is an answered option the review
 * no longer offers. A refused save writes nothing; a row this save finds is updated, never added to.
 */
final class CompleteReview
{
    public const NOTHING_CHOSEN = 'Choose next week\'s calories first.';

    public const NEEDS_AMOUNT = 'Enter how many calories a day you want for Choose my own before saving.';

    public const OUT_OF_DATE = 'Your review has changed since you answered. Choose next week\'s calories again.';

    /**
     * @return array{dates: list<string>, mesocycle: ?string} next week's days, Y-m-d, and the mesocycle whose deload moved
     *
     * @throws DomainException when no review is open for that week, or its answers do not build (422)
     */
    public function __invoke(User $user, string $weekStart): array
    {
        return DB::transaction(fn (): array => $this->save($user, $weekStart));
    }

    /** @return array{dates: list<string>, mesocycle: ?string} */
    private function save(User $user, string $weekStart): array
    {
        $analysis = OpenReview::analysis($user, $weekStart);
        $stored = ReviewAnswer::forWeek($user, $weekStart);
        if ($stored?->needsAmount()) {
            throw new DomainException(self::NEEDS_AMOUNT);
        }
        // An answer the review stopped offering (a stall that has cleared) is refused, not swapped for another option.
        if ($stored !== null && ! in_array($stored->option, OpenReview::offered($analysis), true)) {
            throw new DomainException(self::OUT_OF_DATE);
        }
        $choices = new ReviewChoices($analysis, $stored?->answers() ?? ReviewAnswers::defaults());
        $week = $choices->saveWeek();
        if (! $week instanceof BuiltWeek) {
            throw new DomainException($week instanceof WeekBuildRefusal ? ReviewMessages::refusal($week) : self::NOTHING_CHOSEN);
        }
        $selected = $analysis->mode === ReviewMode::Full ? $choices->selected() : null;
        $droppedFatigue = $selected === 'drop_fatigue';
        $moved = $droppedFatigue ? $choices->movedDeload() : null;

        $next = self::row($user, $analysis->period->next->start);
        $next->fill([
            'is_deload' => (bool) $next->is_deload || $moved !== null,
            'is_diet_break' => (bool) $next->is_diet_break || $droppedFatigue,
        ])->save();

        $dates = $this->saveDays($user, $week);

        $mesocycle = null;
        if ($moved !== null && $analysis->deloadMesocycleID !== null) {
            $mesocycle = Mesocycle::query()->where('user_id', $user->id)->whereKey($analysis->deloadMesocycleID)->first();
            $mesocycle?->update(['weeks' => $moved->weeks]);
        }

        $profile = Profile::query()->where('user_id', $user->id)->sole();
        $steps = $selected === null ? null : self::stepPlan($analysis, $choices, $selected, $profile->step_plan ?? []);
        if ($steps !== null) {
            $profile->update(['step_plan' => $steps]);
        }

        // Last, so the review is done only once everything else is written.
        $reviewed = self::row($user, $analysis->period->reviewed->start);
        $reviewed->reviewed_at = CarbonImmutable::now();
        $reviewed->save();

        return ['dates' => $dates, 'mesocycle' => $mesocycle === null ? null : (string) $mesocycle->id];
    }

    /** The user's stored plan week starting on `$start`, or a new one with no flags. */
    private static function row(User $user, DateTimeImmutable $start): PlanWeek
    {
        $date = $start->format('Y-m-d');
        $row = PlanWeek::query()->where('user_id', $user->id)->whereDate('start_date', $date)->first();
        if ($row === null) {
            $row = new PlanWeek(['start_date' => $date, 'is_deload' => false, 'is_diet_break' => false, 'is_away' => false]);
            $row->user_id = $user->id;
        }

        return $row;
    }

    /**
     * Next week's days (NUT-030). A date that has a target is replaced on its row and keeps its meal ids by position, so
     * only meals beyond the new count go, with their foods; a new date gets a new row. A day with no target writes
     * nothing (REV-005).
     *
     * @return list<string> the week's dates, Y-m-d
     */
    private function saveDays(User $user, BuiltWeek $week): array
    {
        $dates = array_map(fn (WeekDay $day) => $day->day->date->format('Y-m-d'), $week->days);
        $stored = DayTarget::query()->where('user_id', $user->id)->whereIn('date', $dates)->orderBy('created_at')->get()
            ->keyBy(fn (DayTarget $row): string => TrainSnapshot::day($row->date)->format('Y-m-d'));

        foreach ($week->days as $index => $day) {
            if ($day->target === null) {
                continue;
            }
            $row = $stored->get($dates[$index]);
            if ($row === null) {
                $row = new DayTarget(['date' => $dates[$index]]);
                $row->user_id = $user->id;
            }
            // A stored day keeps its meal ids by position, so only meals beyond the new count go, with their foods.
            $attributes = DayTargetRow::from($day);
            $mealIds = array_column($row->meals ?? [], 'id');
            foreach ($attributes['meals'] as $position => $meal) {
                $attributes['meals'][$position]['id'] = $mealIds[$position] ?? $meal['id'];
            }
            $row->fill($attributes)->save();
        }

        return $dates;
    }

    /**
     * REV-027, REV-050: the step plan to store, when the user chose a change; null to leave it. Adding a stall's steps
     * wins over the step offer, as in rp-clone.
     *
     * @param  array<array-key, array{weekday: int, min: int, max: int}>  $stored  the profile's step plan
     * @return ?list<array{weekday: int, min: int, max: int}>
     */
    private static function stepPlan(ReviewAnalysis $analysis, ReviewChoices $choices, string $selected, array $stored): ?array
    {
        $plan = array_map(
            fn (array $day) => new StepPlanDay((int) $day['weekday'], (int) $day['min'], (int) $day['max']),
            array_values($stored),
        );
        $addSteps = $choices->layout()['stall']->addSteps ?? null;
        $changed = match (true) {
            $selected === 'add_steps' && $addSteps !== null => StepPlanUpdate::adding($addSteps, $plan),
            $choices->answers->updateStepPlan && $analysis->stepOffer !== null => StepPlanUpdate::matching($analysis->stepOffer, $plan),
            default => null,
        };

        return $changed === null ? null : array_map(
            fn (StepPlanDay $day) => ['weekday' => $day->weekday, 'min' => $day->min, 'max' => $day->max],
            $changed,
        );
    }
}
