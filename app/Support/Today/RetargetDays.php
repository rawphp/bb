<?php

namespace App\Support\Today;

use App\Domain\Model\Day;
use App\Domain\Model\DayType;
use App\Domain\Model\Meal;
use App\Domain\Model\MealTarget;
use App\Domain\Model\WeighIn;
use App\Domain\Placement\ClockRange;
use App\Domain\Placement\ClockTime;
use App\Domain\Retarget\OtherActivity;
use App\Domain\Retarget\RetargetContext;
use App\Domain\Retarget\RetargetDay;
use App\Domain\Retarget\RetargetMeal;
use App\Domain\WeekBuilder\MealNames;
use App\Domain\WeekBuilder\WeekMealKind;
use App\Domain\WeekBuilder\WeekWorkout;
use App\Domain\Weight\CurrentWeight;
use App\Models\DayTarget;
use App\Models\OtherActivity as OtherActivityRow;
use App\Models\PlanWeek as PlanWeekRow;
use App\Models\Profile;
use App\Models\User;
use App\Models\WeighIn as WeighInRow;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/**
 * A stored day as the re-target rule reads it, and the rule's day written back (SCH-050 to SCH-057). Ported from
 * rp-clone's TodayModel+Retarget. Only checked-in meals are fixed (SCH-055); locks, hand-edited targets and hand-set
 * times are not stored yet.
 */
final class RetargetDays
{
    /** Shown when a day cannot be re-targeted at all, for want of its target, the eating window or a weight. */
    public const CANNOT = 'Couldn\'t update this day\'s meals. Nothing was changed.';

    /** The day as the rule sees it, with `$session` on it. */
    public static function day(DayPlan $plan, ?WeekWorkout $session, ?OtherActivity $activity, RetargetContext $context): RetargetDay
    {
        $window = $context->eatingWindow;
        $meals = array_map(fn (DayMeal $meal) => new RetargetMeal(
            meal: new Meal($meal->id, $meal->name, $meal->target),
            time: $meal->time ?? $window->start,
            kind: $meal->name === MealNames::INTRA_WORKOUT_SHAKE ? WeekMealKind::IntraWorkoutShake : WeekMealKind::Regular,
            isCheckedIn: $meal->isChecked(),
        ), $plan->meals);
        usort($meals, fn (RetargetMeal $a, RetargetMeal $b) => $window->offset($a->time) <=> $window->offset($b->time));

        return new RetargetDay(new Day($plan->date, $plan->type), $session, $activity, $meals, $plan->target);
    }

    /** SCH-032: the day's Other activity, if it has one. */
    public static function activity(User $user, string $date): ?OtherActivity
    {
        $row = OtherActivityRow::query()->where('user_id', $user->id)->whereDate('date', $date)->first();

        return $row === null ? null : new OtherActivity((string) $row->name, (int) $row->minutes, DayType::from($row->intensity));
    }

    /**
     * NUT-003, SCH-041, SCH-060: the trend weight, the eating window, the day's busy periods, the deload and an away
     * week on rest-day calories.
     *
     * @throws DomainException without an eating window or a weight
     */
    public static function context(User $user, DateTimeImmutable $date, bool $isDeloadWeek, ?PlanWeekRow $week): RetargetContext
    {
        $profile = Profile::query()->where('user_id', $user->id)->first();
        $window = $profile?->getAttribute('eating_window');
        $start = ClockTime::fromHhmm((string) ($window['start'] ?? ''));
        $end = ClockTime::fromHhmm((string) ($window['end'] ?? ''));
        $weighIns = WeighInRow::query()->where('user_id', $user->id)->get()
            ->map(fn (WeighInRow $row) => new WeighIn((string) $row->id, DayPlans::instant($row->weighed_at), (float) $row->weight_kg))
            ->values()->all();
        $weightKg = CurrentWeight::fromWeighIns($weighIns, $profile?->weight_kg, new DateTimeZone('UTC'));
        if ($start === null || $end === null || $weightKg === null) {
            throw new DomainException(self::CANNOT);
        }
        $isoWeekday = (int) $date->format('N');
        $busy = [];
        foreach ($profile->getAttribute('busy_periods') ?? [] as $period) {
            $from = ClockTime::fromHhmm((string) ($period['start'] ?? ''));
            $to = ClockTime::fromHhmm((string) ($period['end'] ?? ''));
            if ($from !== null && $to !== null && in_array($isoWeekday, $period['weekdays'] ?? range(1, 7), false)) {
                $busy[] = new ClockRange($from, $to);
            }
        }

        return new RetargetContext(
            weightKg: $weightKg,
            eatingWindow: new ClockRange($start, $end),
            busy: $busy,
            isDeloadWeek: $isDeloadWeek,
            countsWorkoutEnergy: ! ($week !== null && $week->is_away && $week->away_calories === 'rest_day'),
        );
    }

    /**
     * Saves the rule's day onto its stored row: the day's type and totals, and its meals. Stored meals keep their ids,
     * names and order; a new meal (an intra-workout shake) goes last; a meal the rule removed goes with its plan.
     */
    public static function save(DayTarget $row, RetargetDay $day): void
    {
        $byId = [];
        foreach ($day->meals as $meal) {
            $byId[$meal->id()] = $meal;
        }
        $written = fn (RetargetMeal $meal, array $stored) => array_replace($stored, [
            'id' => $meal->id(),
            'name' => $stored['name'] ?? $meal->meal->name,
            'time' => (string) $meal->time,
            'kcal' => $meal->meal->target->kcal,
            'protein_g' => $meal->meal->target->proteinG,
            'fat_g' => $meal->meal->target->fatG,
            'carbs_g' => $meal->meal->target->carbsG,
        ]);
        $meals = [];
        foreach ($row->meals as $stored) {
            if (isset($byId[$stored['id']])) {
                $meals[] = $written($byId[$stored['id']], $stored);
                unset($byId[$stored['id']]);
            }
        }
        foreach ($byId as $meal) {
            $meals[] = $written($meal, []);
        }

        $row->fill([
            'day_type' => $day->day->type,
            'kcal' => $day->target->kcal,
            'protein_g' => $day->target->proteinG,
            'fat_g' => $day->target->fatG,
            'carbs_g' => $day->target->carbsG,
            'meals' => $meals,
        ])->save();
    }

    /** A day with no stored target, for a session's source day that is past and is not saved. */
    public static function emptyDay(DateTimeImmutable $date, ?WeekWorkout $session): RetargetDay
    {
        return new RetargetDay(new Day($date, DayType::Rest), $session, null, [], new MealTarget(0, 0, 0, 0));
    }
}
