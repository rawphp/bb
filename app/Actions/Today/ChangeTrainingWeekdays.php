<?php

namespace App\Actions\Today;

use App\Actions\Settings\SaveProfile;
use App\Actions\Settings\ShowSettings;
use App\Capabilities\Support\FieldRefused;
use App\Capabilities\Today\Data\TrainingWeekdaysChangeInput;
use App\Domain\Mesocycle\Exercise as DomainExercise;
use App\Domain\Mesocycle\MesocycleCalendar;
use App\Domain\Mesocycle\MesocycleRules;
use App\Domain\Mesocycle\StimulusToFatigue;
use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\Mesocycle as DomainMesocycle;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Session;
use App\Domain\Placement\ClockTime;
use App\Domain\Placement\TrainingDay;
use App\Domain\Placement\WorkoutTime;
use App\Domain\Plan\PlanRules;
use App\Domain\Progress\PlanWeekDays;
use App\Domain\Retarget\RetargetRefused;
use App\Domain\Retarget\TrainingWeekdays;
use App\Domain\Retarget\WeekdayChange\Kind as ChangeKind;
use App\Domain\Retarget\WeekdayChoice;
use App\Domain\Retarget\WeekdayChoice\Kind as ChoiceKind;
use App\Models\Mesocycle;
use App\Models\Profile;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Plan\PlanSnapshot;
use App\Support\Today\TodaySnapshot;
use App\Support\UserDay;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SCH-039: a change of training weekdays, from Settings or Edit schedule. Ported from rp-clone's
 * TrainingWeekdayChange, TodayModel+WeekdayChange and SettingsModel's weekday choice.
 *
 * The new weekdays apply from the next plan week. While a mesocycle covers that week:
 * - this plan week's sessions are pinned to their days first, a row each, so the new weekdays do not move them;
 * - the same number of days re-maps the sessions onto the new weekdays, and the mesocycle is unchanged;
 * - fewer days must choose (`fewer_days`): `restructure` lays the muscles out over the new days from next week,
 *   `extend` keeps all sessions and lets each week finish fewer, so the mesocycle runs longer. Without a choice the
 *   change is refused on `fewer_days`, with what each choice does;
 * - more days asks nothing: the sessions take the first weekdays and the rest stay free.
 *
 * The weekdays are saved on the profile and the days after today rebuilt as a settings save (SET-004, SaveProfile),
 * then today, the one day of this week the rebuild leaves, is re-targeted for the new schedule unless a meal of it is
 * checked in.
 */
final class ChangeTrainingWeekdays
{
    public const NO_DAYS = 'Pick at least one training day.';

    /** SCH-056: the toast when today is re-targeted. */
    public const CANNOT_EXTEND = 'Your mesocycle can\'t run longer: the next one starts too soon. Choose restructure instead.';

    public const RETARGETED = 'Meals updated for your new schedule';

    public function __construct(private readonly SaveProfile $save) {}

    /** @return array{toast: ?string, dates: list<string>, today: ?array{date: string, week: list<string>}, mesocycle_id: ?string} the toast of today's re-target, the dates the rebuild wrote, today and its plan week when re-targeted, and the mesocycle changed */
    public function __invoke(User $user, TrainingWeekdaysChangeInput $input): array
    {
        $weekdays = array_values(array_unique($input->weekdays));
        sort($weekdays);
        if ($weekdays === []) {
            throw new FieldRefused('weekdays', self::NO_DAYS);
        }

        /** @var ?array{mesocycle: Mesocycle, fromWeek: int, train: TrainSnapshot, result: ?DomainMesocycle, restructure: bool, weekdays: list<int>} $covering */
        $covering = null;
        /** @var bool $changed whether the weekday set changed; set under the profile's lock */
        $changed = false;
        /** @var list<array{weekday: int, start: string, minutes: int}> $trainingDays */
        $trainingDays = [];
        /** @var ?array{mesocycle: Mesocycle, train: TrainSnapshot, toWeek: int} $running */
        $running = null;
        /** @var list<string> $kept */
        $kept = [];
        // One transaction: today's re-target commits with the change or not at all, so a retry finds it undone.
        [$rebuilt, $today] = DB::transaction(function () use ($user, $weekdays, $input, &$covering, &$changed, &$trainingDays, &$running, &$kept): array {
            $rebuilt = ($this->save)($user, [], function (Profile $profile) use ($user, $weekdays, $input, &$covering, &$changed, &$trainingDays, &$running): void {
                $old = TodaySnapshot::json($profile->training_days);
                $trainingDays = self::trainingDays($old, $weekdays);
                $changed = self::sorted(array_values(array_unique(array_map(fn (mixed $day) => (int) (is_array($day) ? ($day['weekday'] ?? 0) : 0), $old)))) !== $weekdays;
                $covering = $changed ? self::covering($user, $old, $trainingDays, $input->fewer_days) : null;
                $running = $changed ? self::running($user, $covering) : null;
                $profile->fill(['training_days' => $trainingDays]);
            }, function () use ($user, &$covering, &$changed, &$kept, &$running): bool {
                if (! $changed) {
                    return false;
                }
                // This week's sessions stay on their days whether or not a mesocycle runs next week: Today's by their
                // rows, Train's and Workout's by the weeks of this week's mesocycle stored as they ran.
                $kept = self::pinThisWeek($user);
                if ($covering !== null) {
                    self::store($covering);
                }
                if ($running !== null) {
                    self::freeze($running['mesocycle'], $running['toWeek'], $running['train']);
                }

                return true;
            });

            return [$rebuilt, $changed ? self::retargetToday($user, $trainingDays, $kept) : null];
        }, 3);

        return [
            'toast' => $today === null ? null : self::RETARGETED,
            'dates' => $rebuilt,
            'today' => $today,
            'mesocycle_id' => $covering === null ? null : (string) $covering['mesocycle']->id,
        ];
    }

    /**
     * The profile's training days on `$weekdays`: a weekday that stays keeps its start and minutes, a new one starts as
     * Settings starts a new training day.
     *
     * @param  array<array-key, mixed>  $old
     * @param  list<int>  $weekdays
     * @return list<array{weekday: int, start: string, minutes: int}>
     */
    private static function trainingDays(array $old, array $weekdays): array
    {
        return array_map(function (int $weekday) use ($old) {
            $kept = array_find($old, fn (mixed $day) => is_array($day) && (int) ($day['weekday'] ?? 0) === $weekday);

            return [
                'weekday' => $weekday,
                'start' => (string) ($kept['start'] ?? ShowSettings::NEW_TRAINING_DAY_START),
                'minutes' => (int) ($kept['minutes'] ?? ShowSettings::NEW_TRAINING_DAY_MINUTES),
            ];
        }, $weekdays);
    }

    /**
     * The mesocycle covering next plan week, when the change touches it, with what the change makes of it: null for a
     * re-map or more days, else the mesocycle as `$fewerDays` reshapes it.
     *
     * @param  array<array-key, mixed>  $old
     * @param  list<array{weekday: int, start: string, minutes: int}>  $new
     * @return ?array{mesocycle: Mesocycle, fromWeek: int, train: TrainSnapshot, result: ?DomainMesocycle, restructure: bool, weekdays: list<int>}
     *
     * @throws FieldRefused on `fewer_days` when fewer days come without a choice
     */
    private static function covering(User $user, array $old, array $new, ?string $fewerDays): ?array
    {
        $oldDays = self::domainDays($old);
        $newDays = self::domainDays($new);
        $weekdaysOf = fn (array $days) => array_values(array_unique(array_map(fn (TrainingDay $day) => $day->weekday, $days)));
        if (self::sorted($weekdaysOf($oldDays)) === self::sorted($weekdaysOf($newDays))) {
            return null;
        }
        // The plan row is already locked by SaveProfile; a locking read sees the plan as it is now.
        $plan = PlanSnapshot::load($user, lock: true);
        $next = $plan?->nextWeek();
        $placed = $next === null ? null : $plan->mesocycleCoveringWeek($next);
        if ($placed === null || $placed->isEnded) {
            return null;
        }
        $fromWeek = PlanRules::mesocycleWeek($next, $placed->placed->startWeek, $plan->pausedWeeks);
        // The rows are read with a lock, so the mesocycle is written from what it is now.
        $train = TrainSnapshot::load($user, (string) $placed->mesocycle->id, locked: true);
        $mesocycle = $train->mesocycle((string) $placed->mesocycle->id);
        $domain = $train->domain($mesocycle);
        if ($fromWeek < 0 || $fromWeek >= $domain->weeks) {
            return null;
        }

        $change = TrainingWeekdays::change($domain, $oldDays, $newDays, $fromWeek);
        $weekdaysIso = array_map(fn (array $day) => $day['weekday'], $new);
        $days = count($weekdaysOf($newDays));
        $fewer = $days < count($weekdaysOf($oldDays));
        if ($change->kind === ChangeKind::Choose && $fewerDays === null && $fewer) {
            throw new FieldRefused('fewer_days', self::question($change->choices, $days, $domain->weeks));
        }
        // More days asks nothing, and a choice sent with them is not applied: the sessions take the first weekdays.
        $result = match ($change->kind === ChangeKind::Choose && $fewer ? $fewerDays : null) {
            'restructure' => TrainingWeekdays::restructured($domain, $days, $fromWeek, self::exercises($train, $mesocycle), fn () => Str::lower((string) Str::ulid())),
            'extend' => TrainingWeekdays::keepingAllSessions($domain, $days, $fromWeek),
            default => null,
        };
        // MESO-042: running longer must still end before the next mesocycle starts, and within the longest length.
        if ($fewerDays === 'extend' && $result !== null && ! $plan->calendar($mesocycle)->lengthRange()->contains($result->weeks)) {
            throw new FieldRefused('fewer_days', self::CANNOT_EXTEND);
        }
        $grid = $train->grid();

        return [
            'mesocycle' => $mesocycle,
            'fromWeek' => $fromWeek,
            'train' => $train,
            // Keeping all sessions with as many days as every week's sessions changes nothing.
            'result' => $result === $domain ? null : $result,
            'restructure' => $result !== null && $fewerDays === 'restructure',
            'weekdays' => $grid === null ? $weekdaysIso : PlanWeekDays::inPlanWeekOrder($weekdaysIso, $train->reviewIsoWeekday($grid)),
        ];
    }

    /**
     * The mesocycle running this plan week, when it is not the one `covering()` reshapes: its last week, or one the next
     * mesocycle follows. Its weeks up to and including this one are then stored as they ran, and nothing else of it
     * changes, so Train and Workout keep them where they ran.
     *
     * @param  ?array{mesocycle: Mesocycle}  $covering
     * @return ?array{mesocycle: Mesocycle, train: TrainSnapshot, toWeek: int}
     */
    private static function running(User $user, ?array $covering): ?array
    {
        $plan = PlanSnapshot::load($user, lock: true);
        $week = $plan?->todayWeek;
        $placed = $week === null ? null : $plan->mesocycleCoveringWeek($week);
        if ($placed === null || $placed->isEnded || (string) $placed->mesocycle->id === (string) ($covering['mesocycle']->id ?? '')) {
            return null;
        }
        $train = TrainSnapshot::load($user, (string) $placed->mesocycle->id, locked: true);
        $mesocycle = $train->mesocycle((string) $placed->mesocycle->id);
        $toWeek = min((int) $mesocycle->weeks, PlanRules::mesocycleWeek($week, $placed->placed->startWeek, $plan->pausedWeeks) + 1);

        return $toWeek <= 0 ? null : ['mesocycle' => $mesocycle, 'train' => $train, 'toWeek' => $toWeek];
    }

    /** The weeks before `$toWeek` (0 is the first) stored as they ran, each floating day on the weekday it ran on. */
    private static function freeze(Mesocycle $mesocycle, int $toWeek, TrainSnapshot $train): void
    {
        $frozen = MesocycleRules::freezingWeeks($train->rotationAsRun($mesocycle), $mesocycle->earlierWeekRows(), $mesocycle->laterWeekIds(), $toWeek);
        $mesocycle->update(['earlier_weeks' => self::anchored($frozen['earlier_weeks'], $mesocycle, $train), 'later_weeks' => $frozen['later_weeks']]);
    }

    /**
     * What each choice does, as the user reads it (rp-clone's WeekdayChangePrompt copy).
     *
     * @param  list<WeekdayChoice>  $choices
     */
    private static function question(array $choices, int $days, int $weeks): string
    {
        $count = $days === 1 ? '1 day' : "{$days} days";

        return implode(' ', array_map(fn (WeekdayChoice $choice) => match ($choice->kind) {
            ChoiceKind::Restructure => $choice->underTrained === []
                ? "Restructure from next week: the Meso Builder lays out your muscles over {$count}. Your mesocycle stays {$weeks} weeks."
                : "Restructure from next week: your muscles go over {$count}. Trained less than the minimum for this phase: "
                    .implode(', ', array_map(fn (MuscleGroup $muscle) => $muscle->title(), $choice->underTrained)).'.',
            ChoiceKind::KeepAllSessions => "Keep all sessions: each week finishes {$days} of your sessions, in order. Your mesocycle runs {$choice->weeks} weeks instead of {$weeks}.",
        }, $choices));
    }

    /**
     * This plan week's sessions that have no row and are not logged, each given a row on the day they are on now at
     * their time, so they stay there whatever the training weekdays become. A session with a row keeps it.
     *
     * @return list<string> the days (Y-m-d) whose session already had a row, or was logged: it keeps the time it has
     */
    private static function pinThisWeek(User $user): array
    {
        $snapshot = TodaySnapshot::loadWeek($user);
        $grid = $snapshot->train->grid();
        $index = $grid?->weekIndexContaining($snapshot->today());
        if ($index === null) {
            return [];
        }
        $kept = [];
        foreach ($grid->planWeek($index)->days() as $day) {
            $workout = $snapshot->workout($day);
            $scheduled = $snapshot->planning($day)['plan']->session ?? null;
            if ($workout === null || $scheduled === null) {
                continue;
            }
            $rowed = WorkoutSession::query()->where('user_id', $user->id)->where('mesocycle_id', $workout->mesocycle->id)
                ->where('week', $workout->week)->where('day', $workout->day)->exists();
            if ($rowed || $workout->isLogged()) {
                $kept[] = $day->format('Y-m-d');

                continue;
            }
            PlannedSession::saveRow($user, null, (string) $workout->mesocycle->id, $workout->week, $workout->day, $day, $scheduled->time);
        }

        return $kept;
    }

    /**
     * Stores the mesocycle covering next week for the new weekdays (rp-clone's `replacingWeeks`).
     *
     * The weeks before `fromWeek` are stored as they ran first, whatever the change.
     * - A re-map, more days, or keeping all sessions: each rotation day takes the new weekday in its place, in plan-week
     *   order, so Train and Workout put next week's sessions where Today does; with a different number of days the days
     *   float. Keeping all sessions also stores the length and the later weeks.
     * - Restructure: the rotation is the new sessions from `fromWeek` on.
     *   A stored slot keeps its exercise, a new one takes the exercise its muscle had; a new session has no weekday.
     *
     * @param  array{mesocycle: Mesocycle, fromWeek: int, train: TrainSnapshot, result: ?DomainMesocycle, restructure: bool, weekdays: list<int>}  $covering
     */
    private static function store(array $covering): void
    {
        ['mesocycle' => $mesocycle, 'fromWeek' => $fromWeek, 'train' => $train, 'result' => $result] = $covering;
        // This week and the weeks before it are stored as they ran, on their old weekdays, so Train and Workout keep
        // them where Today pins them (TrainSnapshot::weekdays() reads a stored week's own days).
        $frozen = MesocycleRules::freezingWeeks($train->rotationAsRun($mesocycle), $mesocycle->earlierWeekRows(), $mesocycle->laterWeekIds(), $fromWeek);
        $changes = ['earlier_weeks' => self::anchored($frozen['earlier_weeks'], $mesocycle, $train), 'later_weeks' => $frozen['later_weeks']];
        if ($result !== null) {
            $changes = [...$changes, 'weeks' => $result->weeks, 'later_weeks' => $result->laterWeeks];
        }
        if ($result !== null && $covering['restructure']) {
            $slots = [];
            $exerciseIds = [];
            foreach ($mesocycle->dayRows() as $day) {
                foreach ($day['slots'] as $slot) {
                    $slots[$slot['id']] = $slot;
                    $name = $train->exercise($slot['exercise_id'])->name ?? null;
                    if ($name !== null) {
                        $exerciseIds[$slot['muscle_group'].'|'.$name] ??= $slot['exercise_id'];
                    }
                }
            }
            $changes['days'] = array_map(fn (Session $session) => [
                'id' => $session->id,
                'label' => $session->name,
                'weekday' => null,
                'slots' => array_map(fn (ExerciseSlot $slot) => [
                    'id' => $slot->id,
                    'exercise_id' => $slots[$slot->id]['exercise_id'] ?? $exerciseIds[$slot->muscle->value.'|'.$slot->exerciseName] ?? null,
                    'muscle_group' => $slot->muscle->value,
                    'sets' => $slot->sets,
                ], $session->slots),
            ], $result->sessions);
            $changes['days_per_week'] = count($result->sessions);
        } else {
            $weekdays = $covering['weekdays'];
            $stored = $mesocycle->days ?? [];
            $changes['days'] = array_map(
                fn (mixed $day, int $index) => array_replace((array) $day, ['weekday' => count($stored) === count($weekdays) ? $weekdays[$index] : null]),
                $stored,
                array_keys($stored),
            );
        }
        $mesocycle->update($changes);
    }

    /**
     * The stored weeks with each floating day (no weekday) given the weekday it runs on now, from the training
     * weekdays before the change (`$train` read the profile before it saved), so a floating week stays where it ran.
     *
     * @param  list<list<array<string, mixed>>>  $weeks
     * @return list<list<array<string, mixed>>>
     */
    private static function anchored(array $weeks, Mesocycle $mesocycle, TrainSnapshot $train): array
    {
        $calendar = $train->calendar($mesocycle);
        $review = $train->reviewIsoWeekday($calendar->grid);
        foreach ($weeks as $week => $days) {
            $dates = MesocycleCalendar::dayDates($calendar->planWeek($week), array_map(fn (array $day) => $day['weekday'] ?? null, $days), $train->trainingWeekdays(), $review);
            foreach ($days as $index => $day) {
                if (($day['weekday'] ?? null) === null && ($dates[$index] ?? null) !== null) {
                    $weeks[$week][$index]['weekday'] = (int) $dates[$index]->format('N');
                }
            }
        }

        return $weeks;
    }

    /**
     * The exercises of the mesocycle's slots, for the restructure's slot order (MESO-046).
     *
     * @return list<DomainExercise>
     */
    private static function exercises(TrainSnapshot $train, Mesocycle $mesocycle): array
    {
        $exercises = [];
        foreach ($mesocycle->dayRows() as $day) {
            foreach ($day['slots'] as $slot) {
                $exercise = $train->exercise($slot['exercise_id']);
                if ($exercise !== null) {
                    $exercises[$exercise->id] = new DomainExercise($exercise->name, $exercise->muscle_group, StimulusToFatigue::from($exercise->sfr_rating->value), $exercise->is_compound);
                }
            }
        }

        return array_values($exercises);
    }

    /**
     * SCH-039: today re-targeted with the new schedule (TrainingWeekdays::retargetThisWeek), the one day of this plan
     * week the rebuild leaves. A session that had a row or was logged keeps the time it has (rp-clone's
     * `weekdayChangeKeptTime`). Nothing changes on a day with no meals, a checked-in meal, or that the rule refuses.
     *
     * @param  list<array{weekday: int, start: string, minutes: int}>  $trainingDays
     * @param  list<string>  $kept  the days whose session keeps its time, Y-m-d
     * @return ?array{date: string, week: list<string>} today and its plan week, when re-targeted
     */
    private static function retargetToday(User $user, array $trainingDays, array $kept): ?array
    {
        $date = UserDay::today($user)->format('Y-m-d');
        try {
            $day = RetargetableDay::load($user, $date);
        } catch (DomainException) {
            return null;
        }
        $weekday = (int) UserDay::today($user)->format('N');
        $training = in_array($date, $kept, true)
            ? array_values(array_filter($trainingDays, fn (array $training) => $training['weekday'] !== $weekday))
            : $trainingDays;
        try {
            $result = TrainingWeekdays::retargetThisWeek([$day->day], self::domainDays($training), $day->context, RetargetableDay::newId(...));
        } catch (RetargetRefused) {
            return null;
        }
        if ($result->days === []) {
            return null;
        }
        $day->save($result->days[0]);

        return ['date' => $date, 'week' => $day->weekDates()];
    }

    /**
     * @param  array<array-key, mixed>  $days  the profile's training days
     * @return list<TrainingDay>
     */
    private static function domainDays(array $days): array
    {
        return array_values(array_filter(array_map(function (mixed $day) {
            $start = is_array($day) ? ClockTime::fromHhmm((string) ($day['start'] ?? '')) : null;

            return $start === null ? null : new TrainingDay(
                PlanWeekDays::calendarWeekdayFromIso((int) $day['weekday']),
                new WorkoutTime($start, (int) ($day['minutes'] ?? 60)),
            );
        }, $days)));
    }

    /**
     * @param  list<int>  $values
     * @return list<int>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
