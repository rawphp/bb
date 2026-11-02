<?php

namespace App\Capabilities\Reads\Train;

use App\Capabilities\Mesocycles\Data\MesocycleBuild;
use App\Capabilities\Mesocycles\Data\MesocycleBuildDayRow;
use App\Capabilities\Mesocycles\Data\MesocycleBuildSlotRow;
use App\Capabilities\Mesocycles\Data\MesocycleDay;
use App\Capabilities\Mesocycles\Data\MesocycleDayExercise;
use App\Capabilities\Mesocycles\Data\MesocycleDetail;
use App\Capabilities\Mesocycles\Data\MesocycleEndPrompt;
use App\Capabilities\Mesocycles\Data\MesocycleExerciseOption;
use App\Capabilities\Mesocycles\Data\MesocycleMuscleExercises;
use App\Capabilities\Mesocycles\Data\MesocycleMuscleSets;
use App\Capabilities\Mesocycles\Data\MesocycleOption;
use App\Capabilities\Mesocycles\Data\MesocyclePriorityOption;
use App\Capabilities\Mesocycles\Data\MesocyclePriorityRow;
use App\Capabilities\Mesocycles\Data\MesocycleShowOutput;
use App\Capabilities\Mesocycles\Data\MesocycleWeek;
use App\Capabilities\Support\Tone;
use App\Capabilities\Train\Data\Pill;
use App\Domain\Defaults\TBL012TrainingDefaults;
use App\Domain\Defaults\TBL012TrainingDefaults\Column;
use App\Domain\Defaults\TBL012TrainingDefaults\RepRangeBias;
use App\Domain\Mesocycle\MesoBuilder;
use App\Domain\Mesocycle\MesoBuilder\Warning;
use App\Domain\Mesocycle\MesocycleCalendar;
use App\Domain\Mesocycle\MesocycleRules;
use App\Domain\Model\ClosedRange;
use App\Domain\Model\Mesocycle as DomainMesocycle;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Priority;
use App\Domain\Model\Session;
use App\Domain\Progression\Deload;
use App\Enums\MesocycleRoute;
use App\Models\DayTarget;
use App\Models\Exercise;
use App\Models\Mesocycle;
use App\Models\WorkoutSet;
use App\Support\Mesocycles\TrainSnapshot;
use DateTimeImmutable;

/**
 * One mesocycle (MESO-040 to MESO-046 saved; MESO-003 to MESO-007 and MESO-065 for a draft), from a
 * {@see TrainSnapshot}.
 */
final class MesocyclePayload
{
    public const END_WARNING = 'Skipping the deload is not recommended for intermediate and advanced lifters.';

    public const DIET_BREAK_OFFER = 'You are in fat loss. Start a diet break next week?';

    /** MESO-003: no phase covers the start of the draft. */
    public const NO_PHASE = MesocycleRules::NO_PHASE;

    /** MESO-020: days per week in the Meso Builder. */
    public const DAYS_PER_WEEK = MesoBuilder::DAYS_PER_WEEK;

    /** MESO-014: sets of a slot. */
    public const SETS = [1, 20];

    public static function build(TrainSnapshot $snapshot, Mesocycle $mesocycle): MesocycleShowOutput
    {
        return new MesocycleShowOutput(
            id: (string) $mesocycle->id,
            name: $mesocycle->name,
            priority_options: self::priorityOptions(withOff: $mesocycle->isSaved()),
            saved: $mesocycle->isSaved() ? self::saved($snapshot, $mesocycle) : null,
            // A row saved without dates cannot be shown as running, so it opens in the create flow to be finished.
            build: $mesocycle->isSaved() ? null : self::draft($snapshot, $mesocycle),
        );
    }

    // MARK: a saved mesocycle

    private static function saved(TrainSnapshot $snapshot, Mesocycle $mesocycle): MesocycleDetail
    {
        $calendar = $snapshot->calendar($mesocycle);
        $current = $calendar->currentWeek();
        $now = $calendar->isRunning() && $calendar->hasStarted();
        $laterWeeksStored = $mesocycle->laterWeekIds() !== [];
        $domain = $snapshot->domain($mesocycle);
        $weekdays = $snapshot->weekdays($mesocycle);

        $weeks = [];
        for ($index = 0; $index < $calendar->weeks; $index++) {
            $weeks[] = self::week($snapshot, $mesocycle, $domain, $weekdays, $calendar, $index, $now && $index + 1 === $current);
        }

        $phaseType = $snapshot->phaseType($mesocycle);

        return new MesocycleDetail(
            phase_title: TrainWords::phase($phaseType),
            length_text: TrainWords::weeks($calendar->weeks).($now ? " · week {$current} now" : ''),
            weeks_count: $calendar->weeks,
            can_shorten: $calendar->canShorten($laterWeeksStored),
            can_lengthen: $calendar->canLengthen($laterWeeksStored),
            can_add_day: $calendar->canAddDay(),
            end: $calendar->canEnd() ? new MesocycleEndPrompt(
                warning: self::END_WARNING,
                diet_break_offer: $snapshot->endOffersDietBreak($mesocycle) ? self::DIET_BREAK_OFFER : null,
            ) : null,
            weeks: $weeks,
            priorities: self::priorityRows($mesocycle->muscle_priorities ?? []),
        );
    }

    /**
     * MESO-040, MESO-041: one week's column. A day logged counts its working sets by the exercise's muscle; a day
     * still ahead counts its plan, halved in a deload (PROG-041, PROG-042).
     *
     * @param  array<string, ?int>  $weekdays  each stored day's weekday by its id
     */
    private static function week(
        TrainSnapshot $snapshot, Mesocycle $mesocycle, DomainMesocycle $domain, array $weekdays, MesocycleCalendar $calendar, int $index, bool $isNow,
    ): MesocycleWeek {
        $number = $index + 1;
        $planWeek = $calendar->planWeek($index);
        $isLast = $number === $calendar->weeks;
        $deloaded = $snapshot->isDeloadWeek($calendar, $index);
        $weekIsPast = ($planWeek->end() ?? $planWeek->start) < $snapshot->today;
        $lastDay = $calendar->lastDay();
        $ended = $calendar->endedOn !== null;

        $sessions = $domain->sessionsInWeek($index);
        $dates = MesocycleCalendar::dayDates(
            $planWeek,
            array_map(fn (Session $session) => $weekdays[$session->id] ?? null, $sessions),
            $snapshot->trainingWeekdays(),
            $snapshot->reviewIsoWeekday($calendar->grid),
        );

        /** @var array<string, int> $muscleSets */
        $muscleSets = [];
        $days = [];
        foreach ($sessions as $dayIndex => $planned) {
            $session = $deloaded ? Deload::session($planned) : $planned;
            $date = $dates[$dayIndex];
            $logged = $snapshot->loggedSession($mesocycle, $number, $dayIndex + 1);

            if ($logged !== null) {
                $state = $logged->is_skipped ? 'skipped' : ($logged->actual_end_at !== null ? 'done' : 'started');
                foreach ($snapshot->loggedSets($logged) as $set) {
                    $muscle = self::muscleOf($snapshot, $set);
                    if ($muscle !== null) {
                        $muscleSets[$muscle] = ($muscleSets[$muscle] ?? 0) + 1;
                    }
                }
            } elseif ($ended && ($date ?? $planWeek->start) > $lastDay) {
                // MESO-043: dropped by the early end.
                $state = 'not_planned';
            } elseif ($weekIsPast || ($date !== null && $date < $snapshot->today)) {
                $state = 'missed';
            } else {
                $state = 'upcoming';
                foreach ($session->slots as $slot) {
                    $muscleSets[$slot->muscle->value] = ($muscleSets[$slot->muscle->value] ?? 0) + $slot->sets;
                }
            }

            $days[] = new MesocycleDay(
                day: $dayIndex + 1,
                title: 'Day '.($dayIndex + 1).' · '.$planned->name,
                label: $planned->name,
                state: $state,
                can_open: $state !== 'not_planned',
                exercises: array_map(
                    fn ($slot) => new MesocycleDayExercise(slot_id: $slot->id, name: $slot->exerciseName, sets: $slot->sets),
                    $session->slots,
                ),
            );
        }

        return new MesocycleWeek(
            number: $number,
            title: $isLast ? 'Deload' : "Week {$number}",
            now: $isNow ? new Pill('Now', Tone::Info->value) : null,
            diet_break: $snapshot->isDietBreakWeek($mesocycle, $calendar, $index) ? new Pill('Diet break', Tone::Info->value) : null,
            calories_text: self::calories($snapshot, $planWeek->days()),
            muscle_sets: array_values(array_filter(array_map(
                fn (MuscleGroup $muscle) => ($muscleSets[$muscle->value] ?? 0) > 0
                    ? new MesocycleMuscleSets($muscle->title(), $muscleSets[$muscle->value])
                    : null,
                MuscleGroup::cases(),
            ))),
            days: $days,
        );
    }

    private static function muscleOf(TrainSnapshot $snapshot, WorkoutSet $set): ?string
    {
        return $snapshot->exercise($set->exercise_id)?->muscle_group->value;
    }

    /**
     * MESO-041: the mean daily calorie target of the week's days that have one.
     *
     * @param  list<DateTimeImmutable>  $days
     */
    private static function calories(TrainSnapshot $snapshot, array $days): ?string
    {
        $kcal = $snapshot->dayTargets()
            ->filter(fn (DayTarget $target) => in_array(TrainSnapshot::day($target->date), $days))
            ->pluck('kcal');

        return $kcal->isEmpty() ? null : TrainWords::kcalPerDay((int) round($kcal->avg()));
    }

    // MARK: a draft

    /** The create flow's state (MESO-003 to MESO-007, MESO-013, MESO-014, MESO-020, MESO-021, MESO-065). */
    private static function draft(TrainSnapshot $snapshot, Mesocycle $mesocycle): MesocycleBuild
    {
        $route = $mesocycle->route ?? MesocycleRoute::Scratch;
        $days = $mesocycle->dayRows();
        // The options list the whole library, so read it once, before the slots look up their names in it.
        $library = $snapshot->exercises();
        $weeksRange = MesocycleRules::lengthRange($snapshot->experience());
        $weeks = (int) max($weeksRange->lower, min($weeksRange->upper, $mesocycle->weeks ?? MesocycleRules::defaultLength($snapshot->experience())));

        $start = $snapshot->buildStart($mesocycle);
        $phase = $snapshot->phaseCovering($start);
        $column = $snapshot->buildColumn($mesocycle);
        $priorities = $snapshot->buildPriorities($mesocycle);
        $training = $snapshot->trainingWeekdays();
        // MESO-005: days are labelled as the saved view labels them ({@see Mesocycle::dayRows()}); a split with any day
        // on a weekday is a weekday split.
        $pinned = array_any($days, fn (array $day) => $day['weekday'] !== null);

        return new MesocycleBuild(
            route: $route->value,
            resume_step: $days !== [] ? 'slots' : ($route === MesocycleRoute::Builder ? 'builder' : 'scratch'),
            weeks: $weeks,
            weeks_options: self::options($weeksRange, 'week'),
            start_date: $start->format('Y-m-d'),
            start_text: TrainWords::date($start),
            label_style: $pinned ? 'weekdays' : 'floating',
            weekdays_available: $training !== [],
            phase_title: $phase?->type->title(),
            rep_range_text: $phase === null ? null : self::repRanges(TBL012TrainingDefaults::row($column)->repRangeBias),
            phase_warning: $phase === null ? new Pill(self::NO_PHASE, Tone::Warning->value) : null,
            days_per_week: $mesocycle->days_per_week ?? MesoBuilder::daysPerWeek($days, $training),
            days_per_week_options: self::options(new ClosedRange(...self::DAYS_PER_WEEK), 'day'),
            priorities: self::priorityRows($priorities),
            warnings: array_map(
                fn (Warning $warning) => new Pill($warning->message(), Tone::Warning->value),
                MesoBuilder::warnings(
                    array_map(Priority::from(...), array_filter($priorities, fn ($p) => $p !== null)),
                    $phase->type ?? PhaseType::Maintenance,
                ),
            ),
            priority_notice: self::draftPriorityNotice($snapshot, $mesocycle, $start, $priorities),
            days: array_map(fn (array $day) => new MesocycleBuildDayRow(
                id: $day['id'],
                label: $day['label'],
                slots: array_map(fn (array $slot) => new MesocycleBuildSlotRow(
                    id: $slot['id'],
                    muscle: $slot['muscle_group'],
                    muscle_title: MuscleGroup::from($slot['muscle_group'])->title(),
                    sets: $slot['sets'],
                    sets_text: TrainWords::count($slot['sets'], 'set'),
                    exercise_id: $slot['exercise_id'] === null ? null : (string) $slot['exercise_id'],
                    exercise_name: $snapshot->exercise($slot['exercise_id'])?->name,
                ), $day['slots']),
            ), $days),
            sets_options: self::options(new ClosedRange(...self::SETS), 'set'),
            exercise_options: array_map(fn (MuscleGroup $muscle) => new MesocycleMuscleExercises(
                muscle: $muscle->value,
                muscle_title: $muscle->title(),
                exercises: $library
                    ->filter(fn (Exercise $exercise) => $exercise->muscle_group === $muscle)
                    ->sortBy('name')
                    ->map(fn (Exercise $exercise) => new MesocycleExerciseOption((string) $exercise->id, $exercise->name))
                    ->values()->all(),
            ), MuscleGroup::cases()),
            can_pick_for_me: array_any($days, fn (array $day) => array_any($day['slots'], fn (array $slot) => $slot['exercise_id'] === null)),
            can_finish: trim((string) $mesocycle->name) !== '' && $days !== [] && $phase !== null,
        );
    }

    /**
     * MESO-065: a copy whose priorities were reset to the new phase's preset says so, judged against the mesocycle it
     * was copied from.
     *
     * @param  array<string, ?string>  $priorities
     */
    private static function draftPriorityNotice(TrainSnapshot $snapshot, Mesocycle $draft, DateTimeImmutable $start, array $priorities): ?Pill
    {
        $source = $draft->copied_from_id === null ? null
            : $snapshot->mesocycles->first(fn (Mesocycle $m) => $m->id === $draft->copied_from_id);
        if ($draft->route !== MesocycleRoute::CopyPrevious || $source === null) {
            return null;
        }
        $phase = $snapshot->priorityReset($source, $start);
        if ($phase === null) {
            return null;
        }
        $preset = array_map(fn (Priority $p) => $p->value, MesoBuilder::defaultPriorities(Column::for($phase->type, $draft->is_resensitization)));

        return $priorities == $preset ? new Pill(TrainPayload::priorityNotice($snapshot, $source, $start) ?? '', Tone::Info->value) : null;
    }

    /** PH-008: "10–20 and 20–30 reps", "Mixed rep ranges, with more 5–10". */
    private static function repRanges(RepRangeBias $bias): string
    {
        return match (true) {
            $bias->moreFiveToTen => 'Mixed rep ranges, with more 5–10',
            $bias->mixed => 'Mixed rep ranges',
            default => implode(' and ', array_map(fn (ClosedRange $range) => "{$range->lower}–{$range->upper}", $bias->ranges)).' reps',
        };
    }

    // MARK: shared

    /** @return list<MesocycleOption> */
    private static function options(ClosedRange $range, string $noun): array
    {
        return array_map(fn (int $value) => new MesocycleOption($value, TrainWords::count($value, $noun)), range((int) $range->lower, (int) $range->upper));
    }

    /**
     * MESO-020, MESO-034: Emphasize, Grow, Maintain, and Off for a saved mesocycle.
     *
     * @return list<MesocyclePriorityOption>
     */
    private static function priorityOptions(bool $withOff): array
    {
        $options = array_map(fn (Priority $p) => new MesocyclePriorityOption($p->value, $p->title()), Priority::cases());

        return $withOff ? [...$options, new MesocyclePriorityOption(null, 'Off')] : $options;
    }

    /**
     * MESO-030, MESO-033, MESO-034: all 14 muscles in order with their bars. A muscle left out reads as Maintain;
     * a null is Off.
     *
     * @param  array<string, ?string>  $stored
     * @return list<MesocyclePriorityRow>
     */
    private static function priorityRows(array $stored): array
    {
        return array_map(function (MuscleGroup $muscle) use ($stored) {
            $priority = array_key_exists($muscle->value, $stored)
                ? ($stored[$muscle->value] === null ? null : Priority::from($stored[$muscle->value]))
                : Priority::Maintain;

            return new MesocyclePriorityRow(
                muscle: $muscle->value,
                muscle_title: $muscle->title(),
                priority: $priority?->value,
                priority_title: $priority === null ? 'Off' : $priority->title(),
                bars: $priority?->bars() ?? 0,
            );
        }, MuscleGroup::cases());
    }
}
