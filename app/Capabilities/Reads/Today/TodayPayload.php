<?php

namespace App\Capabilities\Reads\Today;

use App\Capabilities\Reads\Data\Today\ActiveRest;
use App\Capabilities\Reads\Data\Today\ActivityDraft;
use App\Capabilities\Reads\Data\Today\ActivityMenuItem;
use App\Capabilities\Reads\Data\Today\AddWorkout;
use App\Capabilities\Reads\Data\Today\AddWorkoutSession;
use App\Capabilities\Reads\Data\Today\ApplyToChoice;
use App\Capabilities\Reads\Data\Today\Bar;
use App\Capabilities\Reads\Data\Today\BusyPeriodValue;
use App\Capabilities\Reads\Data\Today\DaySchedule;
use App\Capabilities\Reads\Data\Today\ManualCheckIn;
use App\Capabilities\Reads\Data\Today\MealCheckInState;
use App\Capabilities\Reads\Data\Today\MealMenuItem;
use App\Capabilities\Reads\Data\Today\MoveDayOption;
use App\Capabilities\Reads\Data\Today\NextMesocycle;
use App\Capabilities\Reads\Data\Today\NextMesocycleOption;
use App\Capabilities\Reads\Data\Today\OffPlanEntry;
use App\Capabilities\Reads\Data\Today\ScheduleMenuItem;
use App\Capabilities\Reads\Data\Today\ScheduleValues;
use App\Capabilities\Reads\Data\Today\Status;
use App\Capabilities\Reads\Data\Today\Steps;
use App\Capabilities\Reads\Data\Today\TimelineEntry;
use App\Capabilities\Reads\Data\Today\TodayBusy;
use App\Capabilities\Reads\Data\Today\TodayMeal;
use App\Capabilities\Reads\Data\Today\TodayOtherActivity;
use App\Capabilities\Reads\Data\Today\TodayWeighIn;
use App\Capabilities\Reads\Data\Today\TodayWorkout;
use App\Capabilities\Reads\Data\Today\Totals;
use App\Capabilities\Reads\Data\Today\WeekdayChip;
use App\Capabilities\Reads\Data\Today\WorkoutExercise;
use App\Capabilities\Reads\Data\Today\WorkoutMenuItem;
use App\Capabilities\Reads\Data\TodayShowOutput;
use App\Capabilities\Support\Tone;
use App\Domain\Model\DayType;
use App\Domain\Model\MealCheckInState as CheckIn;
use App\Domain\Model\MealTarget;
use App\Domain\Model\UnitConversion;
use App\Domain\Placement\ClockTime;
use App\Domain\Placement\MealOrder;
use App\Domain\Progress\DayStatus;
use App\Domain\Progress\DayTotals;
use App\Models\OffPlanEntry as OffPlanRow;
use App\Models\OtherActivity;
use App\Models\WeighInScheduleEntry;
use App\Support\Today\TodayMealItem;
use App\Support\Today\TodaySnapshot;
use App\Support\Today\TodayWorkoutItem;
use DateTimeImmutable;
use Illuminate\Support\Str;

/**
 * Today (rp-clone TodayModel, TodayItems, +Timeline, +Meals, +ScheduleMeals, +NextMesocycle): one day's timeline and
 * the controls around it, every value decided here (CAP-001).
 */
final class TodayPayload
{
    /** SCH-008. */
    public const PLAN_BANNER = 'Plan this meal before you eat it';

    /** DAY-003. */
    public const ADJUST_BADGE = 'Adjust foods';

    /** MEAL-045. */
    public const ADJUST_FIRST = "Re-fit this meal's foods or mark it Done planning foods before checking it in.";

    /** SCH-038. */
    public const DAY_HAS_WORKOUT = 'This day already has a workout. Pick a different day.';

    /** SCH-032. */
    public const NO_SESSION_LEFT = 'No session left to move this week';

    /** MESO-060. */
    public const NEXT_TITLE = 'Build your next mesocycle';

    /** MESO-064. */
    public const ACTIVE_REST = 'A week of active rest is recommended before your next mesocycle.';

    /** MESO-060: the create routes, Copy first as the default. */
    private const NEXT_OPTIONS = [
        'copy_previous' => 'Copy this mesocycle',
        'template' => 'Start from a template',
        'builder' => 'Build from scratch',
    ];

    /** SCH-030, in order. */
    private const SCHEDULE_MENU = [
        'add_meal' => 'Add meal',
        'add_workout' => 'Add workout',
        'add_busy_period' => 'Add busy period',
        'copy_day' => 'Copy day',
        'copy_week' => 'Copy week',
        'optimize_schedule' => 'Optimize schedule',
        'edit_schedule' => 'Edit schedule',
    ];

    /** MEAL-009, in order. */
    private const MEAL_MENU = [
        'edit_macros_time' => 'Edit macros/time',
        'load_saved_meal' => 'Load saved meal',
        'save_as_saved_meal' => 'Save as saved meal',
        'copy_foods' => 'Copy foods',
        'remove_foods' => 'Remove foods',
        'check_in_meal' => 'Check in meal',
        'check_in_manual' => 'Check in at manual macros',
        'remove_check_in' => 'Remove check-in',
        'still_planning' => 'Still planning foods',
        'delete_meal' => 'Delete meal',
    ];

    /** SCH-001: the order items take at the same minute. */
    private const KIND_ORDER = ['weigh_in' => 0, 'meal' => 1, 'workout' => 2, 'other_activity' => 3, 'busy' => 4];

    private const WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    public static function build(TodaySnapshot $snapshot): TodayShowOutput
    {
        $day = $snapshot->date;
        $inPlan = ! $snapshot->isBeforePlan($day) && $snapshot->train->profile !== null;
        $meals = $inPlan ? $snapshot->meals($day) : [];
        $workout = $inPlan ? $snapshot->workout($day) : null;
        $weighIn = $inPlan ? self::weighIn($snapshot) : null;
        $activity = $inPlan ? $snapshot->otherActivity($day) : null;
        $busy = $inPlan ? self::busy($snapshot, $day) : [];
        $isPast = $day < $snapshot->today();
        $addMealTime = $isPast ? null : self::addMealTime($snapshot);
        $addWorkout = $isPast || ! $inPlan ? null : self::addWorkout($snapshot, $workout, $activity);

        return new TodayShowOutput(
            date: $day->format('Y-m-d'),
            title: TodayWords::title($day),
            banners: self::banners($snapshot),
            review_available: false,
            next_mesocycle: self::nextMesocycle($snapshot),
            totals: self::totals($snapshot),
            steps: self::steps($snapshot),
            timeline: self::timeline($weighIn, $meals, $workout, $activity, $busy),
            meals: array_map(fn (TodayMealItem $meal) => self::meal($meal, $snapshot), $meals),
            workouts: $workout === null ? [] : [self::workout($workout, $snapshot)],
            weigh_ins: $weighIn === null ? [] : [$weighIn['row']],
            other_activities: $activity === null ? [] : [new TodayOtherActivity(
                id: (string) $activity->id,
                name: $activity->name,
                time_text: self::clock($activity->start_time)->shortText().' · '.$activity->minutes.' min',
                intensity_text: ucfirst($activity->intensity),
                menu: [new ActivityMenuItem('edit', 'Edit'), new ActivityMenuItem('delete', 'Delete')],
            )],
            busy_periods: array_map(fn (array $period) => $period['row'], $busy),
            off_plan: array_map(fn (OffPlanRow $entry) => new OffPlanEntry(
                id: (string) $entry->id,
                name: $entry->name ?? 'Off-plan food',
                time_text: $entry->time === null ? null : ClockTime::fromHhmm($entry->time)?->shortText(),
                macros: TodayWords::macros(new MealTarget($entry->kcal, $entry->protein_g, $entry->fat_g, $entry->carbs_g)),
            ), $inPlan ? $snapshot->offPlan($day) : []),
            schedule_menu: self::scheduleMenu($isPast),
            add_meal_time: $addMealTime,
            add_workout: $addWorkout,
            schedule: self::schedule($snapshot, $workout),
            missed_session: null,
            missed_weeks: null,
        );
    }

    /** @return list<Status> ONB-031: "Your plan starts Sat, Sep 19" until it does. */
    private static function banners(TodaySnapshot $snapshot): array
    {
        $start = $snapshot->planStart();

        return $start !== null && $snapshot->today() < $start
            ? [new Status('Your plan starts '.TodayWords::shortDate($start), Tone::Info->value)]
            : [];
    }

    /** MESO-060, MESO-064: "Build your next mesocycle", with the active rest it recommends until it is taken. */
    private static function nextMesocycle(TodaySnapshot $snapshot): ?NextMesocycle
    {
        $next = $snapshot->train->nextMesocycle();
        if ($next === null) {
            return null;
        }
        $rest = $next->recommendsActiveRest && ! $next->tookActiveRest
            ? $snapshot->train->nextMesocycleStart($next->mesocycle, true)
            : null;

        return new NextMesocycle(
            mesocycle_id: (string) $next->mesocycle->id,
            title: self::NEXT_TITLE,
            start_text: TodayWords::starts($next->start),
            options: array_map(
                fn (string $route, string $label) => new NextMesocycleOption($route, $label),
                array_keys(self::NEXT_OPTIONS),
                self::NEXT_OPTIONS,
            ),
            active_rest: $rest === null ? null : new ActiveRest(
                text: self::ACTIVE_REST,
                start_text: TodayWords::starts($rest),
                start_date: $rest->format('Y-m-d'),
            ),
        );
    }

    /** SCH-006, DAY-010, DAY-011: consumed against the day's target, the status and the day type. */
    private static function totals(TodaySnapshot $snapshot): ?Totals
    {
        $day = $snapshot->date;
        $target = $snapshot->dayTarget($day);
        if ($target === null || $snapshot->isBeforePlan($day)) {
            return null;
        }
        $consumed = $snapshot->consumedForTotals($day);
        $status = DayStatus::make($consumed, new DayTotals($target->kcal, $target->protein_g));
        $deload = $snapshot->isDeload($day) || $target->day_type === DayType::Deload;

        return new Totals(
            day_type: new Status($deload ? 'Deload' : ucfirst($target->day_type->value), Tone::Neutral->value),
            status: new Status($status->text, $status->onTrack ? Tone::Good->value : Tone::Warning->value),
            calories: self::bar('Cal', $consumed->kcal, $target->kcal),
            protein: self::bar('P', $consumed->proteinG, $target->protein_g),
            warnings: [],
            can_redistribute: false,
        );
    }

    private static function bar(string $label, float $consumed, float $target): Bar
    {
        return new Bar(
            label: $label,
            value_text: (int) round($consumed).'/'.(int) round($target),
            fraction: $target > 0 ? round(min(1.0, max(0.0, $consumed / $target)), 3) : 0.0,
            tone: Tone::Neutral->value,
        );
    }

    /** SCH-011: "Step count target 6-8k" from the step plan, with the day's steps when there are any. */
    private static function steps(TodaySnapshot $snapshot): ?Steps
    {
        if ($snapshot->isBeforePlan($snapshot->date)) {
            return null;
        }
        $weekday = (int) $snapshot->date->format('N');
        $range = self::onWeekday(TodaySnapshot::json($snapshot->train->profile?->step_plan), $weekday);
        if ($range === null) {
            return null;
        }

        return new Steps(
            target_text: 'Step count target '.TodayWords::thousands((int) $range['min']).'-'.TodayWords::thousands((int) $range['max']).'k',
            actual_text: $snapshot->steps === null ? null : TodayWords::steps($snapshot->steps->count),
        );
    }

    /**
     * SCH-001: the day's items in time order; at the same minute weigh-in, meal, workout, other activity, busy period.
     *
     * @param  ?array{row: TodayWeighIn, time: ClockTime}  $weighIn
     * @param  list<TodayMealItem>  $meals
     * @param  list<array{row: TodayBusy, start: ClockTime}>  $busy
     * @return list<TimelineEntry>
     */
    private static function timeline(?array $weighIn, array $meals, ?TodayWorkoutItem $workout, ?OtherActivity $activity, array $busy): array
    {
        $items = [];
        if ($weighIn !== null) {
            $items[] = ['weigh_in', $weighIn['row']->id, $weighIn['time']->minuteOfDay];
        }
        foreach ($meals as $meal) {
            $items[] = ['meal', $meal->id, MealOrder::minute($meal->time)];
        }
        if ($workout !== null) {
            $items[] = ['workout', 'workout', $workout->time->minuteOfDay];
        }
        if ($activity !== null) {
            $items[] = ['other_activity', (string) $activity->id, self::clock($activity->start_time)->minuteOfDay];
        }
        foreach ($busy as $period) {
            $items[] = ['busy', $period['row']->id, $period['start']->minuteOfDay];
        }
        $order = array_keys($items);
        usort($order, fn (int $a, int $b) => [$items[$a][2], self::KIND_ORDER[$items[$a][0]], $a] <=> [$items[$b][2], self::KIND_ORDER[$items[$b][0]], $b]);

        return array_map(fn (int $i) => new TimelineEntry($items[$i][0], $items[$i][1]), $order);
    }

    /** SCH-002, SCH-008, DAY-003, MEAL-043, MEAL-045, MEAL-009: a meal card. */
    private static function meal(TodayMealItem $meal, TodaySnapshot $snapshot): TodayMeal
    {
        $kcal = (int) round($meal->target->kcal).' kcal';
        $state = $meal->checkInState();
        $badge = $meal->showsAdjustBadge();
        $manual = $meal->eaten ?? $meal->plannedCheckIn();

        return new TodayMeal(
            id: $meal->id,
            name: $meal->name,
            time: $meal->time === null ? null : (string) $meal->time,
            time_text: $meal->time?->shortText(),
            condensed_time_text: $meal->time === null ? $kcal : $meal->time->shortText().' · '.$kcal,
            macros: TodayWords::macros($meal->target),
            planned_text: $meal->hasEntries()
                ? implode(' · ', array_map(fn ($entry) => "{$entry->name} {$entry->amount}", $meal->entries()))
                : null,
            eaten_text: $meal->eaten === null ? null
                : 'Eaten: '.(int) round($meal->eaten->kcal).' kcal, '.(int) round($meal->eaten->proteinG).' g protein',
            plan_banner: $meal->showsPlanBanner() ? self::PLAN_BANNER : null,
            badges: $badge ? [new Status(self::ADJUST_BADGE, Tone::Warning->value)] : [],
            check_in: new MealCheckInState(
                state: match ($state) {
                    CheckIn::NotCheckedIn => 'not_checked_in',
                    CheckIn::OnTrack => 'on_track',
                    CheckIn::Under => 'under',
                    CheckIn::Over => 'over',
                },
                label: $state->label(),
                blocked_message: $badge && ! $meal->isChecked() ? self::ADJUST_FIRST : null,
                manual: new ManualCheckIn(
                    time: $meal->eatenTime ?? ($meal->time === null ? $snapshot->nowTime : (string) $meal->time),
                    kcal: round($manual->kcal, 1),
                    protein_g: round($manual->proteinG, 1),
                    fat_g: round($manual->fatG, 1),
                    carbs_g: round($manual->carbsG, 1),
                ),
            ),
            menu: self::mealMenu($meal, $badge),
        );
    }

    /** @return list<MealMenuItem> MEAL-009: check in is off while the meal has the Adjust foods badge (MEAL-045). */
    private static function mealMenu(TodayMealItem $meal, bool $badge): array
    {
        $items = [];
        foreach (self::MEAL_MENU as $action => $label) {
            $enabled = match ($action) {
                'check_in_meal' => ! $meal->isChecked() && ! $badge,
                'remove_check_in' => $meal->isChecked(),
                'save_as_saved_meal', 'remove_foods' => $meal->foods !== [],
                'copy_foods' => $meal->hasEntries(),
                default => true,
            };
            if ($action === 'still_planning' && $meal->planningState === TodayMealItem::STILL_PLANNING) {
                [$action, $label] = ['done_planning', 'Done planning foods'];
            }
            $items[] = new MealMenuItem($action, $label, $enabled);
        }

        return $items;
    }

    /** SCH-003, SCH-046, SCH-051, SCH-053: a workout card. */
    private static function workout(TodayWorkoutItem $workout, TodaySnapshot $snapshot): TodayWorkout
    {
        $sets = $workout->workingSets();
        $movable = $snapshot->isMovable($workout);
        $slots = $workout->session->slots;

        return new TodayWorkout(
            id: 'workout',
            mesocycle_id: (string) $workout->mesocycle->id,
            week: $workout->week,
            day: $workout->day,
            title: self::workoutTitle($workout),
            name: $workout->session->name,
            time: (string) $workout->time,
            time_text: $workout->time->shortText(),
            condensed_time_text: $workout->time->shortText().' · '.$workout->minutes.' min',
            summary_text: implode(' · ', [
                $workout->minutes.' min', TodayWords::count(count($slots), 'exercise'), TodayWords::count($sets, 'set'),
            ]),
            exercises: array_map(fn ($slot) => new WorkoutExercise($slot->exerciseName, TodayWords::count($slot->sets, 'set')), $slots),
            state: $workout->state,
            status_text: match ($workout->state) {
                TodayWorkoutItem::DONE => 'Done',
                TodayWorkoutItem::SKIPPED => 'Skipped',
                default => null,
            },
            completed: $workout->setsCompleted === null ? null
                : new Status("{$workout->setsCompleted} of {$sets} sets completed", Tone::Good->value),
            menu: $movable ? [new WorkoutMenuItem('change_time', 'Change time'), new WorkoutMenuItem('move_day', 'Move to another day')] : [],
            move_days: $movable ? self::moveDays($snapshot, $workout->date) : [],
        );
    }

    /** @return list<MoveDayOption> SCH-038, SCH-051: a day that holds a session is shown but not offered. */
    private static function moveDays(TodaySnapshot $snapshot, DateTimeImmutable $from): array
    {
        return array_map(fn (array $day) => new MoveDayOption(
            date: $day['date']->format('Y-m-d'),
            label: TodayWords::shortDate($day['date']),
            enabled: ! $day['taken'],
            note: $day['taken'] ? self::DAY_HAS_WORKOUT : null,
        ), $snapshot->moveDays($from));
    }

    /** @return ?array{row: TodayWeighIn, time: ClockTime} SCH-004: on a scheduled weekday, its time and the day's first weight. */
    private static function weighIn(TodaySnapshot $snapshot): ?array
    {
        $weekday = (int) $snapshot->date->format('N');
        $entry = $snapshot->weighInSchedule->first(fn (WeighInScheduleEntry $e) => in_array($weekday, array_map('intval', $e->weekdays ?? []), true));
        $time = $entry === null ? null : ClockTime::fromHhmm((string) $entry->time);
        if ($time === null) {
            return null;
        }
        $kg = $snapshot->weighIns->first()?->weight_kg;
        $lb = ($snapshot->train->profile->weight_unit ?? 'kg') === 'lb';

        return ['time' => $time, 'row' => new TodayWeighIn(
            id: 'weigh-in',
            time_text: $time->shortText(),
            weight_text: $kg === null ? null
                : number_format($lb ? UnitConversion::kgToLb((float) $kg) : (float) $kg, 1).($lb ? ' lb' : ' kg'),
        )];
    }

    /** @return list<array{row: TodayBusy, start: ClockTime}> SCH-005: the profile's busy periods on this weekday. */
    private static function busy(TodaySnapshot $snapshot, DateTimeImmutable $day): array
    {
        $rows = [];
        foreach (self::busyOn($snapshot, $day) as $period) {
            $start = ClockTime::fromHhmm((string) $period['start']);
            $end = ClockTime::fromHhmm((string) $period['end']);
            if ($start !== null && $end !== null) {
                $rows[] = ['start' => $start, 'row' => new TodayBusy(
                    id: 'busy-'.Str::slug((string) $period['label']).'-'.str_replace(':', '', (string) $start),
                    label: (string) $period['label'],
                    time_text: $start->shortText().' – '.$end->shortText(),
                )];
            }
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private static function busyOn(TodaySnapshot $snapshot, DateTimeImmutable $day): array
    {
        $weekday = (int) $day->format('N');

        return array_values(array_filter(
            TodaySnapshot::json($snapshot->train->profile?->busy_periods),
            fn (array $period) => in_array($weekday, array_map('intval', $period['weekdays'] ?? []), true),
        ));
    }

    /** @return list<ScheduleMenuItem> SCH-030, NUT-024: a past day offers neither Add meal nor Optimize. */
    private static function scheduleMenu(bool $isPast): array
    {
        $items = [];
        foreach (self::SCHEDULE_MENU as $action => $label) {
            if (! $isPast || ($action !== 'add_meal' && $action !== 'optimize_schedule')) {
                $items[] = new ScheduleMenuItem($action, $label);
            }
        }

        return $items;
    }

    /** SCH-031: the middle of the eating window, to the 5 minutes before; null on a day with no target. */
    private static function addMealTime(TodaySnapshot $snapshot): ?string
    {
        $window = TodaySnapshot::json($snapshot->train->profile?->eating_window);
        $start = ClockTime::fromHhmm((string) ($window['start'] ?? ''));
        $end = ClockTime::fromHhmm((string) ($window['end'] ?? ''));
        if ($start === null || $end === null || $snapshot->dayTarget($snapshot->date) === null) {
            return null;
        }

        return (string) $start->adding(intdiv(intdiv($start->minutesUntil($end), 2), 5) * 5);
    }

    /**
     * SCH-032, SCH-038: the Add workout sheet: the session that would move here, or why none would; and the day's
     * stored Other activity.
     */
    private static function addWorkout(TodaySnapshot $snapshot, ?TodayWorkoutItem $workout, ?OtherActivity $activity): AddWorkout
    {
        $next = $snapshot->nextMovableSession($snapshot->date);

        return new AddWorkout(
            session: $next === null ? null : new AddWorkoutSession(
                mesocycle_id: (string) $next->mesocycle->id,
                week: $next->week,
                day: $next->day,
                title: self::workoutTitle($next),
                time_text: $next->time->shortText(),
            ),
            session_message: $next !== null ? null : ($workout !== null ? self::DAY_HAS_WORKOUT : self::NO_SESSION_LEFT),
            activity: $activity === null ? null : new ActivityDraft(
                id: (string) $activity->id,
                name: $activity->name,
                start: (string) self::clock($activity->start_time),
                minutes: $activity->minutes,
                intensity: $activity->intensity,
            ),
        );
    }

    /** "Week 3 · Day 2 · Upper A". */
    private static function workoutTitle(TodayWorkoutItem $workout): string
    {
        return "Week {$workout->week} · Day {$workout->day} · {$workout->session->name}";
    }

    /** SCH-033, SCH-037, SCH-039: what Edit schedule opens with, from the profile's schedule. */
    private static function schedule(TodaySnapshot $snapshot, ?TodayWorkoutItem $workout): ?DaySchedule
    {
        $profile = $snapshot->train->profile;
        if ($profile === null) {
            return null;
        }
        $weekday = (int) $snapshot->date->format('N');
        $window = TodaySnapshot::json($profile->eating_window);
        $training = self::onWeekday(TodaySnapshot::json($profile->training_days), $weekday);
        $values = fn (?string $trainingStart) => new ScheduleValues(
            window_start: (string) ($window['start'] ?? ''),
            window_end: (string) ($window['end'] ?? ''),
            meal_count: (int) $profile->meal_count,
            busy_periods: array_map(fn (array $period) => new BusyPeriodValue(
                label: (string) $period['label'],
                start: (string) $period['start'],
                end: (string) $period['end'],
            ), self::busyOn($snapshot, $snapshot->date)),
            training_start: $trainingStart,
        );
        $trainingDays = array_map(fn (array $day) => (int) $day['weekday'], TodaySnapshot::json($profile->training_days));

        return new DaySchedule(
            apply_to: [new ApplyToChoice('this_day', 'This day'), new ApplyToChoice('all_future_days', 'All future days')],
            this_day: $values($workout === null ? null : (string) $workout->time),
            all_future_days: $values($training === null ? null : (string) $training['start']),
            training_weekdays: array_map(
                fn (int $iso, string $label) => new WeekdayChip($iso, $label, in_array($iso, $trainingDays, true)),
                array_keys(self::WEEKDAYS),
                self::WEEKDAYS,
            ),
        );
    }

    /**
     * The profile entry for an ISO weekday, if there is one.
     *
     * @param  array<array-key, mixed>  $rows
     * @return ?array<string, mixed>
     */
    private static function onWeekday(array $rows, int $weekday): ?array
    {
        foreach ($rows as $row) {
            if (is_array($row) && (int) ($row['weekday'] ?? 0) === $weekday) {
                return $row;
            }
        }

        return null;
    }

    private static function clock(string $hhmm): ClockTime
    {
        return ClockTime::fromHhmm($hhmm) ?? ClockTime::at(0);
    }
}
