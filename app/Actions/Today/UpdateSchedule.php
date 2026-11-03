<?php

namespace App\Actions\Today;

use App\Actions\Settings\SaveProfile;
use App\Capabilities\Support\FieldRefused;
use App\Capabilities\Today\Data\ScheduleBusyPeriod;
use App\Capabilities\Today\Data\ScheduleUpdateInput;
use App\Domain\Model\Meal;
use App\Domain\Model\MealTarget;
use App\Domain\Placement\ClockRange;
use App\Domain\Placement\ClockTime;
use App\Domain\Placement\WorkoutTime;
use App\Domain\Retarget\RetargetContext;
use App\Domain\Retarget\RetargetDay;
use App\Domain\Retarget\Retargeting;
use App\Domain\Retarget\RetargetMeal;
use App\Domain\Retarget\RetargetRefusal;
use App\Domain\Retarget\RetargetRefused;
use App\Domain\WeekBuilder\WeekMealKind;
use App\Models\Profile;
use App\Models\User;
use App\Support\Today\DayPlans;
use App\Support\Today\TodaySnapshot;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SCH-037: Edit schedule, "Apply to: this day / all future days". Ported from rp-clone's TodayModel+EditSchedule and
 * ScheduleSheetModels.
 *
 * - This day: the day's unchecked meals are placed again with the edited eating window, meal count, busy periods and
 *   training time, in one Retargeting::placed, and a new training time moves the session's row with workout_time_set's
 *   session write (PlannedSession::saveRow).
 *   Nothing of the schedule is stored: a later re-target of the day goes back to the profile's. Checked-in and locked
 *   meals keep their times and targets (SCH-055); a past day never changes (NUT-024).
 * - All future days: the eating window and meal count are saved on the profile, and the busy periods and training
 *   time for the day's weekday, then the days after today are rebuilt (SET-004, through SaveProfile).
 */
final class UpdateSchedule
{
    /** SCH-056: the toast when the session keeps its time. */
    public const RETARGETED = 'Meals updated for this day\'s schedule';

    public const DOES_NOT_FIT = 'Your meals can\'t fit in this schedule. Nothing was changed.';

    public const MEAL_COUNT = 'Pick between 2 and 6 meals a day.';

    /** The meals a day the Edit schedule and Settings forms offer. */
    private const MEALS_MIN = 2;

    private const MEALS_MAX = 6;

    public function __construct(private readonly SaveProfile $save) {}

    /**
     * This day: the toast, the day and its plan week, and the session it re-timed. All future days: the dates the
     * rebuild wrote, with no toast or week.
     *
     * @return array{toast: ?string, dates: list<string>, week: ?list<string>, session: ?array{mesocycle_id: string, week: int, day: int}}
     */
    public function __invoke(User $user, ScheduleUpdateInput $input): array
    {
        if ($input->meal_count < self::MEALS_MIN || $input->meal_count > self::MEALS_MAX) {
            throw new FieldRefused('meal_count', self::MEAL_COUNT);
        }

        foreach ($input->busy_periods as $index => $period) {
            if (trim($period->label) === '') {
                throw new FieldRefused("busy_periods.{$index}.label", SetBusyPeriod::NO_LABEL);
            }
            if ($period->start === $period->end) {
                throw new FieldRefused("busy_periods.{$index}.end", SetBusyPeriod::NO_LENGTH);
            }
        }

        return $input->apply_to === 'this_day' ? $this->thisDay($user, $input) : $this->allFutureDays($user, $input);
    }

    /** @return array{toast: string, dates: list<string>, week: list<string>, session: ?array{mesocycle_id: string, week: int, day: int}} */
    private function thisDay(User $user, ScheduleUpdateInput $input): array
    {
        return DB::transaction(function () use ($user, $input): array {
            $loaded = RetargetableDay::load($user, $input->date);
            // A started, finished or skipped session keeps its time (SCH-038).
            $workout = $loaded->snapshot->workout(DayPlans::day($input->date));
            $start = $input->training_start === null ? null : ClockTime::fromHhmm($input->training_start);
            $moves = $workout !== null && $start !== null && $loaded->snapshot->isMovable($workout) && $start->minuteOfDay !== $workout->time->minuteOfDay;
            $session = $moves ? $loaded->day->session?->startingAt($start) : $loaded->day->session;

            $context = self::context($loaded->context, $input);
            try {
                $placed = Retargeting::placed(self::resized($loaded->day, $input->meal_count), $session, $context, RetargetableDay::newId(...));
            } catch (RetargetRefused $refused) {
                throw $refused->refusal === RetargetRefusal::MealsDoNotFit ? new DomainException(self::DOES_NOT_FIT) : RetargetableDay::refused($refused);
            }
            $loaded->save($placed);
            $moved = null;
            $toast = self::RETARGETED;
            if ($moves) {
                // SCH-050: the session's row moves as workout_time_set moves it, placed once with the edited schedule.
                $planned = PlannedSession::movable($user, (string) $workout->mesocycle->id, $workout->week, $workout->day);
                PlannedSession::saveRow($user, $planned->row, (string) $planned->item->mesocycle->id, $workout->week, $workout->day, $planned->item->date,
                    new WorkoutTime($start, $planned->scheduled->time->lengthMinutes));
                $moved = ['mesocycle_id' => (string) $workout->mesocycle->id, 'week' => $workout->week, 'day' => $workout->day];
                $toast = "Meals updated for your {$start->shortText()} workout";
            }

            return [
                'toast' => $toast,
                'dates' => [$loaded->date],
                'week' => $loaded->weekDates(),
                'session' => $moved,
            ];
        });
    }

    /** @return array{toast: null, dates: list<string>, week: null, session: null} */
    private function allFutureDays(User $user, ScheduleUpdateInput $input): array
    {
        $weekday = (int) DayPlans::day($input->date)->format('N');
        $dates = ($this->save)($user, [
            'eating_window' => ['start' => $input->window_start, 'end' => $input->window_end],
            'meal_count' => $input->meal_count,
        ], function (Profile $profile) use ($input, $weekday): void {
            // Read from the profile SaveProfile has locked, so a change saved meanwhile elsewhere is kept.
            $profile->fill(['busy_periods' => self::busyPeriods(TodaySnapshot::json($profile->busy_periods), $input->busy_periods, $weekday)]);
            $profile->fill(['training_days' => self::trainingDays(TodaySnapshot::json($profile->training_days), $input->training_start, $weekday)]);
        });

        return ['toast' => null, 'dates' => $dates, 'week' => null, 'session' => null];
    }

    /** The day's settings with the edited eating window and busy periods. */
    private static function context(RetargetContext $context, ScheduleUpdateInput $input): RetargetContext
    {
        $range = fn (string $start, string $end) => new ClockRange(
            ClockTime::fromHhmm($start) ?? throw new DomainException('Pick a time.'),
            ClockTime::fromHhmm($end) ?? throw new DomainException('Pick a time.'),
        );

        return new RetargetContext(
            weightKg: $context->weightKg,
            eatingWindow: $range($input->window_start, $input->window_end),
            busy: array_map(fn (ScheduleBusyPeriod $period) => $range($period->start, $period->end), $input->busy_periods),
            carbPlacement: $context->carbPlacement,
            isDeloadWeek: $context->isDeloadWeek,
            countsWorkoutEnergy: $context->countsWorkoutEnergy,
        );
    }

    /**
     * The day with `$count` regular meals: empty ones added for the split to fill, or the last ones that are not
     * fixed taken off. A checked-in or locked meal is never taken off (SCH-055); an intra-workout shake is not counted.
     */
    private static function resized(RetargetDay $day, int $count): RetargetDay
    {
        $meals = $day->meals;
        $regular = count(array_filter($meals, fn (RetargetMeal $meal) => $meal->kind !== WeekMealKind::IntraWorkoutShake));
        while ($regular < $count) {
            $regular++;
            $meals[] = new RetargetMeal(
                meal: new Meal(Str::lower(RetargetableDay::newId()), "Meal {$regular}", new MealTarget(0, 0, 0, 0)),
                time: $meals === [] ? ClockTime::at(0) : $meals[count($meals) - 1]->time,
                kind: WeekMealKind::Regular,
            );
        }
        while ($regular > $count) {
            $last = array_find_key(array_reverse($meals, true), fn (RetargetMeal $meal) => $meal->kind !== WeekMealKind::IntraWorkoutShake && ! $meal->isFixed());
            if ($last === null) {
                break;
            }
            unset($meals[$last]);
            $regular--;
        }

        return $day->withMeals(array_values($meals));
    }

    /**
     * SCH-037 all future days: the busy periods on `$weekday` as edited. The input carries no period's id, so each sent
     * period is matched to one stored on the weekday: the same label, start and end (unchanged); the same label with a
     * start or end in common; the same label; the same start and end. Any other period is read as new, and a stored one
     * left over as removed. A matched one edited keeps the weekdays it repeats on, as Edit schedule says ("A busy period you
     * edit or remove changes on every day it repeats"); a stored one left over was removed; a sent one left over is new
     * on `$weekday` only. Periods not on the weekday are left alone.
     *
     * @param  array<array-key, mixed>  $stored
     * @param  list<ScheduleBusyPeriod>  $sent
     * @return list<mixed>
     */
    private static function busyPeriods(array $stored, array $sent, int $weekday): array
    {
        $stored = array_values($stored);
        $on = array_keys(array_filter($stored, fn (mixed $period) => is_array($period) && in_array($weekday, $period['weekdays'] ?? range(1, 7), false)));
        $label = fn (array $period, ScheduleBusyPeriod $edit) => (string) ($period['label'] ?? '') === trim($edit->label);
        $times = fn (array $period, ScheduleBusyPeriod $edit) => (string) ($period['start'] ?? '') === $edit->start && (string) ($period['end'] ?? '') === $edit->end;
        $oneTime = fn (array $period, ScheduleBusyPeriod $edit) => (string) ($period['start'] ?? '') === $edit->start || (string) ($period['end'] ?? '') === $edit->end;
        $matchers = [
            fn (array $period, ScheduleBusyPeriod $edit) => $label($period, $edit) && $times($period, $edit),
            fn (array $period, ScheduleBusyPeriod $edit) => $label($period, $edit) && $oneTime($period, $edit),
            $label,
            $times,
        ];

        $left = $sent;
        $pair = function (int $match, int $position) use (&$stored, &$on, &$left): void {
            $sent = $left[$position];
            $stored[$on[$match]] = array_replace($stored[$on[$match]], ['label' => trim($sent->label), 'start' => $sent->start, 'end' => $sent->end]);
            unset($on[$match], $left[$position]);
        };
        foreach ($matchers as $matches) {
            foreach ($left as $position => $sent) {
                $match = array_find_key($on, fn (int $index) => $matches($stored[$index], $sent));
                if ($match !== null) {
                    $pair($match, $position);
                }
            }
        }
        $added = array_map(fn (ScheduleBusyPeriod $edit) => [
            'id' => Str::lower((string) Str::ulid()), 'label' => trim($edit->label), 'start' => $edit->start, 'end' => $edit->end, 'weekdays' => [$weekday],
        ], array_values($left));

        return [...array_values(array_diff_key($stored, array_flip($on))), ...$added];
    }

    /**
     * SCH-037 all future days: the training time of `$weekday`, when it is a training day; its minutes stay.
     *
     * @param  array<array-key, mixed>  $stored
     * @return list<mixed>
     */
    private static function trainingDays(array $stored, ?string $start, int $weekday): array
    {
        return array_values(array_map(
            fn (mixed $day) => $start !== null && is_array($day) && (int) ($day['weekday'] ?? 0) === $weekday ? array_replace($day, ['start' => $start]) : $day,
            $stored,
        ));
    }
}
