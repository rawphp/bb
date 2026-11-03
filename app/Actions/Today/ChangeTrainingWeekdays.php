<?php

namespace App\Actions\Today;

use App\Actions\Settings\SaveProfile;
use App\Actions\Settings\ShowSettings;
use App\Capabilities\Support\FieldRefused;
use App\Capabilities\Today\Data\TrainingWeekdaysChangeInput;
use App\Domain\Mesocycle\Exercise as DomainExercise;
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

    public function __construct(private readonly SaveProfile $save) {}

    /** @return array{toast: ?string, dates: list<string>, mesocycle_id: ?string} the re-target's toast, the days changed, and the mesocycle reshaped */
    public function __invoke(User $user, TrainingWeekdaysChangeInput $input): array
    {
        $weekdays = array_values(array_unique($input->weekdays));
        sort($weekdays);
        if ($weekdays === []) {
            throw new FieldRefused('weekdays', self::NO_DAYS);
        }

        /** @var ?array{mesocycle: Mesocycle, fromWeek: int, train: TrainSnapshot, result: ?DomainMesocycle, restructure: bool} $covering */
        $covering = null;
        $trainingDays = [];
        $rebuilt = ($this->save)($user, [], function (Profile $profile) use ($user, $weekdays, $input, &$covering, &$trainingDays): void {
            $old = TodaySnapshot::json($profile->training_days);
            $trainingDays = self::trainingDays($old, $weekdays);
            $covering = self::covering($user, $old, $trainingDays, $input->fewer_days);
            $profile->fill(['training_days' => $trainingDays]);
        }, function () use ($user, &$covering): bool {
            if ($covering === null) {
                return false;
            }
            self::pinThisWeek($user);
            if ($covering['result'] !== null) {
                self::store($covering['mesocycle'], $covering['result'], $covering['restructure'], $covering['fromWeek'], $covering['train']);
            }

            return true;
        });

        $today = self::retargetToday($user, $trainingDays);

        return [
            'toast' => $today === null ? null : 'Meals updated for your new schedule',
            'dates' => array_values(array_unique([...($today === null ? [] : [$today]), ...$rebuilt])),
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
     * @return ?array{mesocycle: Mesocycle, fromWeek: int, train: TrainSnapshot, result: ?DomainMesocycle, restructure: bool}
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
        $train = TrainSnapshot::load($user, (string) $placed->mesocycle->id);
        $mesocycle = $train->mesocycle((string) $placed->mesocycle->id);
        $domain = $train->domain($mesocycle);
        if ($fromWeek < 0 || $fromWeek >= $domain->weeks) {
            return null;
        }

        $change = TrainingWeekdays::change($domain, $oldDays, $newDays, $fromWeek);
        $days = count($weekdaysOf($newDays));
        $fewer = $days < count($weekdaysOf($oldDays));
        if ($change->kind === ChangeKind::Choose && $fewerDays === null && $fewer) {
            throw new FieldRefused('fewer_days', self::question($change->choices, $days, $domain->weeks));
        }
        $result = match ($change->kind === ChangeKind::Choose ? $fewerDays : null) {
            'restructure' => TrainingWeekdays::restructured($domain, $days, $fromWeek, self::exercises($train, $mesocycle), fn () => Str::lower((string) Str::ulid())),
            'extend' => TrainingWeekdays::keepingAllSessions($domain, $days, $fromWeek),
            default => null,
        };

        return [
            'mesocycle' => $mesocycle,
            'fromWeek' => $fromWeek,
            'train' => $train,
            // Keeping all sessions with as many days as every week's sessions changes nothing.
            'result' => $result === $domain ? null : $result,
            'restructure' => $fewerDays === 'restructure',
        ];
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
     */
    private static function pinThisWeek(User $user): void
    {
        $snapshot = TodaySnapshot::loadWeek($user);
        $grid = $snapshot->train->grid();
        $index = $grid?->weekIndexContaining($snapshot->today());
        if ($index === null) {
            return;
        }
        foreach ($grid->planWeek($index)->days() as $day) {
            $workout = $snapshot->workout($day);
            $scheduled = $snapshot->planning($day)['plan']->session ?? null;
            if ($workout === null || $scheduled === null || $workout->isLogged()) {
                continue;
            }
            $rowed = WorkoutSession::query()->where('user_id', $user->id)->where('mesocycle_id', $workout->mesocycle->id)
                ->where('week', $workout->week)->where('day', $workout->day)->exists();
            if (! $rowed) {
                PlannedSession::saveRow($user, null, (string) $workout->mesocycle->id, $workout->week, $workout->day, $day, $scheduled->time);
            }
        }
    }

    /**
     * Stores the mesocycle as the choice reshaped it (rp-clone's `replacingWeeks`). The weeks before `$fromWeek` are
     * stored as they ran. Keep all sessions changes the length and the later weeks only, so the rotation stays as
     * stored. Restructure replaces the rotation with the new sessions: a stored slot keeps its exercise and a new one
     * takes the exercise the muscle had; a new session has no weekday.
     */
    private static function store(Mesocycle $mesocycle, DomainMesocycle $result, bool $restructure, int $fromWeek, TrainSnapshot $train): void
    {
        $frozen = MesocycleRules::freezingWeeks($train->rotationAsRun($mesocycle), $mesocycle->earlierWeekRows(), $mesocycle->laterWeekIds(), $fromWeek);
        $changes = ['weeks' => $result->weeks, 'earlier_weeks' => $frozen['earlier_weeks'], 'later_weeks' => $result->laterWeeks];
        if ($restructure) {
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
        }
        $mesocycle->update($changes);
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
     * week the rebuild leaves. Nothing changes on a day with no meals, a checked-in meal, or that the rule refuses.
     *
     * @param  list<array{weekday: int, start: string, minutes: int}>  $trainingDays
     * @return ?string the day re-targeted, Y-m-d
     */
    private static function retargetToday(User $user, array $trainingDays): ?string
    {
        $date = UserDay::today($user)->format('Y-m-d');
        try {
            $day = RetargetableDay::load($user, $date);
        } catch (DomainException) {
            return null;
        }
        try {
            $result = TrainingWeekdays::retargetThisWeek([$day->day], self::domainDays($trainingDays), $day->context, RetargetableDay::newId(...));
        } catch (RetargetRefused) {
            return null;
        }
        if ($result->days === []) {
            return null;
        }
        $day->save($result->days[0]);

        return $date;
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
