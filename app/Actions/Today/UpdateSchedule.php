<?php

namespace App\Actions\Today;

use App\Actions\Settings\SaveProfile;
use App\Capabilities\Support\FieldRefused;
use App\Capabilities\Today\Data\ScheduleBusyPeriod;
use App\Capabilities\Today\Data\ScheduleUpdateInput;
use App\Capabilities\Today\Data\WorkoutTimeSetInput;
use App\Domain\Model\Meal;
use App\Domain\Model\MealTarget;
use App\Domain\Placement\ClockRange;
use App\Domain\Placement\ClockTime;
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
 * - This day: the day's unchecked meals are placed again with the edited eating window, meal count and busy periods
 *   (Retargeting::placed), and a new training time moves the session's row as workout_time_set does (SCH-050).
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

    public function __construct(private readonly SetWorkoutTime $setWorkoutTime, private readonly SaveProfile $save) {}

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
            $session = null;
            $toast = self::RETARGETED;
            if ($moves) {
                // SCH-050: the session's row moves as workout_time_set moves it; the day is then placed again below.
                $toast = ($this->setWorkoutTime)($user, new WorkoutTimeSetInput($workout->mesocycle->id, $workout->week, $workout->day, $input->training_start))['toast'];
                $loaded = RetargetableDay::load($user, $input->date);
                $session = ['mesocycle_id' => (string) $workout->mesocycle->id, 'week' => $workout->week, 'day' => $workout->day];
            }

            $context = self::context($loaded->context, $input);
            try {
                $placed = Retargeting::placed(self::resized($loaded->day, $input->meal_count), $loaded->day->session, $context, RetargetableDay::newId(...));
            } catch (RetargetRefused $refused) {
                throw $refused->refusal === RetargetRefusal::MealsDoNotFit ? new DomainException(self::DOES_NOT_FIT) : RetargetableDay::refused($refused);
            }
            $loaded->save($placed);

            return [
                'toast' => $toast,
                'dates' => [$loaded->date],
                'week' => $loaded->weekDates(),
                'session' => $session,
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
     * SCH-037 all future days: the busy periods on `$weekday` as edited. A sent period equal to one stored on the
     * weekday keeps it as stored; the rest are paired in order: a stored one paired with a sent one is edited and keeps
     * the weekdays it repeats on, a stored one left over is removed, and a sent one left over is new on `$weekday` only.
     * Periods not on the weekday are left alone.
     *
     * @param  array<array-key, mixed>  $stored
     * @param  list<ScheduleBusyPeriod>  $sent
     * @return list<mixed>
     */
    private static function busyPeriods(array $stored, array $sent, int $weekday): array
    {
        $stored = array_values($stored);
        $on = array_keys(array_filter($stored, fn (mixed $period) => is_array($period) && in_array($weekday, $period['weekdays'] ?? range(1, 7), false)));
        $same = fn (array $period, ScheduleBusyPeriod $edit) => (string) ($period['label'] ?? '') === trim($edit->label)
            && (string) ($period['start'] ?? '') === $edit->start && (string) ($period['end'] ?? '') === $edit->end;

        $unmatched = [];
        foreach ($sent as $edit) {
            $match = array_find_key($on, fn (int $index) => $same($stored[$index], $edit));
            if ($match === null) {
                $unmatched[] = $edit;
            } else {
                unset($on[$match]);
            }
        }

        $removed = [];
        foreach (array_values($on) as $position => $index) {
            $edit = $unmatched[$position] ?? null;
            if ($edit === null) {
                $removed[] = $index;

                continue;
            }
            $stored[$index] = array_replace($stored[$index], ['label' => trim($edit->label), 'start' => $edit->start, 'end' => $edit->end]);
        }
        $added = array_map(fn (ScheduleBusyPeriod $edit) => [
            'id' => Str::lower((string) Str::ulid()), 'label' => trim($edit->label), 'start' => $edit->start, 'end' => $edit->end, 'weekdays' => [$weekday],
        ], array_slice($unmatched, count($on)));

        return [...array_values(array_diff_key($stored, array_flip($removed))), ...$added];
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
