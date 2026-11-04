<?php

namespace App\Support\Review;

use App\Domain\Macros\DayTarget as DomainDayTarget;
use App\Domain\Macros\MacroTargets;
use App\Domain\Macros\WeekTargets;
use App\Domain\MealFit\MealFit;
use App\Domain\Model\ClosedRange;
use App\Domain\Model\DayType;
use App\Domain\Model\FeedbackAnswers;
use App\Domain\Model\JointPain;
use App\Domain\Model\MealCheckInState;
use App\Domain\Model\MealTarget;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Phase as DomainPhase;
use App\Domain\Model\PhaseType;
use App\Domain\Model\PlanWeek as DomainPlanWeek;
use App\Domain\Model\Pump;
use App\Domain\Model\Soreness;
use App\Domain\Model\StepPlanDay;
use App\Domain\Model\VolumeFeedback;
use App\Domain\Model\WeighIn as DomainWeighIn;
use App\Domain\Model\WeightUnit;
use App\Domain\Plan\PlanRules;
use App\Domain\Progression\LoggedExercise;
use App\Domain\Progression\LoggedSet;
use App\Domain\Progression\Underperformance;
use App\Domain\Progression\VolumeProgression;
use App\Domain\Review\BuiltWeekSummary;
use App\Domain\Review\CalorieOptions;
use App\Domain\Review\CalorieRecommendation;
use App\Domain\Review\CalorieReview;
use App\Domain\Review\DeloadMove;
use App\Domain\Review\DeloadMove\Plan as DeloadPlan;
use App\Domain\Review\Fatigue;
use App\Domain\Review\FatigueAdvice;
use App\Domain\Review\FatigueWeek;
use App\Domain\Review\NextWeekCalories;
use App\Domain\Review\RepeatThisWeek;
use App\Domain\Review\ReviewMealItem;
use App\Domain\Review\ReviewMealsSummary;
use App\Domain\Review\ReviewMode;
use App\Domain\Review\ReviewNextWeek;
use App\Domain\Review\ReviewPeriod;
use App\Domain\Review\ReviewProgress;
use App\Domain\Review\ReviewProgressRules;
use App\Domain\Review\ReviewStatus;
use App\Domain\Review\ReviewTiming;
use App\Domain\Review\ReviewTraining;
use App\Domain\Review\ReviewTrainingFacts;
use App\Domain\Review\ReviewTrainingSummary;
use App\Domain\Review\ReviewWeek;
use App\Domain\Review\ReviewWeighInRow;
use App\Domain\Review\ReviewWeighIns;
use App\Domain\Review\ReviewWorld;
use App\Domain\Review\StepPlanCheck;
use App\Domain\Review\StepPlanOffer;
use App\Domain\Review\StepPlanUpdate;
use App\Domain\Review\StoredPlanWeek;
use App\Domain\WeekBuilder\BuiltWeek;
use App\Domain\WeekBuilder\WeekBuildRefusal;
use App\Domain\WeekBuilder\WeekSessions;
use App\Domain\Weight\CurrentWeight;
use App\Domain\Weight\TrendWeight;
use App\Models\DayTarget;
use App\Models\Exercise;
use App\Models\ExerciseFeedback;
use App\Models\MuscleFeedback;
use App\Models\PlanWeek;
use App\Models\Profile;
use App\Models\Step;
use App\Models\User;
use App\Models\WeighIn;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Today\TodaySnapshot;
use DateTimeImmutable;
use DateTimeZone;

/**
 * What the weekly review works out for one user on one day (rp-clone ReviewAnalysis): the five steps' facts, the
 * rules' answers and the world next week is built in. Read only: nothing is written, and the user chooses what is
 * applied (REV-031). Dates are whole days at midnight UTC, as {@see TrainSnapshot} reads them.
 */
final readonly class ReviewAnalysis
{
    /**
     * @param  DomainPlanWeek  $reviewed  the reviewed week with every flag that applies: the row's and the mesocycle's deload
     * @param  list<ReviewWeighInRow>  $weighInRows
     * @param  list<ReviewWeek>  $weeks  finished weeks with a stored target, oldest first; the last is the reviewed week
     *                                   when it has one
     * @param  ?string  $deloadMesocycleID  the mesocycle the deload would be moved in
     * @param  DateTimeImmutable  $today  the review day, a whole day at midnight UTC
     * @param  WeightUnit  $weightUnit  the unit the user's weights are shown in
     */
    private function __construct(
        public ReviewMode $mode,
        public ReviewPeriod $period,
        public DomainPlanWeek $reviewed,
        public ?DomainPhase $phase,
        public ?ReviewWorld $world,
        public array $weighInRows,
        public ReviewMealsSummary $meals,
        public ReviewTrainingSummary $training,
        public ?ReviewProgress $progress,
        public array $weeks,
        public ?CalorieRecommendation $recommendation,
        public ?FatigueAdvice $fatigue,
        public int $weeksSinceBreak,
        public ?StepPlanOffer $stepOffer,
        public ?float $repeatKcal,
        public ?BuiltWeek $plannedNextWeek,
        public ?CalorieOptions $options,
        public DeloadPlan $deload,
        public ?string $deloadMesocycleID,
        public DateTimeImmutable $today,
        public WeightUnit $weightUnit,
    ) {}

    /** The reviewed week's own entry of {@see $weeks}; null when it has no stored target. */
    public function reviewedWeek(): ?ReviewWeek
    {
        foreach ($this->weeks as $week) {
            if ($week->week->start == $this->reviewed->start) {
                return $week;
            }
        }

        return null;
    }

    /** The review open for the user on `$today`; null when none is (no plan, no finished week, too few weigh-ins, or done). */
    public static function for(User $user, DateTimeImmutable|string $today): ?self
    {
        return self::fromData(ReviewData::load($user, $today));
    }

    /**
     * Where the review stands on the loaded data's day (REV-001, REV-002); null when there is no plan or profile to
     * review.
     */
    public static function status(ReviewData $data): ?ReviewStatus
    {
        $context = ReviewPlanContext::from($data);

        return $context === null || $data->profile === null ? null : self::statusIn($data, $context);
    }

    private static function statusIn(ReviewData $data, ReviewPlanContext $context): ReviewStatus
    {
        $stored = $data->planWeeks->map(fn (PlanWeek $row) => new StoredPlanWeek(
            start: TrainSnapshot::day($row->start_date),
            isDeload: (bool) $row->is_deload,
            isDietBreak: (bool) $row->is_diet_break,
            isAway: (bool) $row->is_away,
            reviewedAt: TodaySnapshot::instant($row->reviewed_at),
        ))->values()->all();
        $times = $data->weighIns->map(fn (WeighIn $w) => TodaySnapshot::instant($w->weighed_at))->filter()->values()->all();

        return ReviewTiming::status($data->today, $context->grid, $stored, $times);
    }

    /** The review the loaded data has open on its day; null when none is. */
    public static function fromData(ReviewData $data): ?self
    {
        $context = ReviewPlanContext::from($data);
        $profile = $data->profile;
        if ($context === null || $profile === null) {
            return null;
        }
        $status = self::statusIn($data, $context);
        $period = $status->period();
        if (! $status->isAvailable() || $period === null) {
            return null;
        }
        $mode = match (true) {
            (bool) $profile->training_only => ReviewMode::TrainingOnly,
            $period->reviewed->isAway => ReviewMode::Away,
            default => ReviewMode::Full,
        };

        return self::build($context, $profile, $period, $mode);
    }

    private static function build(ReviewPlanContext $context, Profile $profile, ReviewPeriod $period, ReviewMode $mode): self
    {
        $data = $context->data;
        $weighIns = $data->weighIns->map(fn (WeighIn $w) => new DomainWeighIn(
            (string) $w->id, TodaySnapshot::instant($w->weighed_at) ?? $data->today, (float) $w->weight_kg,
        ))->values()->all();
        $trend = new TrendWeight($weighIns, new DateTimeZone('UTC'));
        $reviewedDays = $period->reviewed->days();

        // Weigh-ins, meals (steps 1 and 2).
        $scheduled = [];
        foreach ($data->weighInSchedule as $entry) {
            foreach (TodaySnapshot::json($entry->weekdays) as $weekday) {
                $scheduled[] = (int) $weekday;
            }
        }
        $weighInRows = ReviewWeighIns::rows($period->reviewed, array_values(array_unique($scheduled)), $weighIns);
        $meals = self::mealsSummary($data, $reviewedDays);

        $reviewedIndex = $period->reviewedIndex;
        $nextIndex = $period->nextIndex;
        $phase = $context->phase($reviewedIndex) ?? $context->phase($nextIndex);
        $fallback = $phase->type ?? PhaseType::Maintenance;
        $flagged = fn (int $index): DomainPlanWeek => $context->flagged($index, $fallback);
        $reviewedWeek = $flagged($reviewedIndex);

        // The world next week is built in (REV-002, REV-010).
        $domainProfile = ReviewPlanRebuild::domainProfile($profile, $trend->currentKg());
        $world = $context->world($profile, $trend->currentKg(), $nextIndex, $fallback);
        $nextCovering = $context->coveringMesocycle($nextIndex, $fallback);
        $planned = self::built($world, NextWeekCalories::planned());
        $nextWeek = $flagged($nextIndex);
        if ($planned !== null) {
            $nextWeek = new DomainPlanWeek(
                $nextWeek->start, $nextWeek->dayCount, $planned->week->isDeload, $planned->week->isDietBreak, $planned->week->isAway,
            );
        }

        // Step 3, training. The sessions are grouped by day and the feedback by session once, for every week read.
        $logged = self::loggedSessions($data);
        $sessionsByDay = self::sessionsByDay($data);
        $feedbackBySession = [];
        foreach ($data->muscleFeedback as $f) {
            $feedbackBySession[strtolower((string) $f->workout_session_id)][] = $f;
        }
        $reviewedCovering = $context->coveringMesocycle($reviewedIndex, $fallback);
        $reviewedRow = $context->row($period->reviewed->start);
        $reviewedSessions = WeekSessions::build(
            planWeek: $reviewedWeek, week: $reviewedIndex, mesocycle: $reviewedCovering['mesocycle'],
            mesocycleStartWeek: $reviewedCovering['startWeek'], trainingDays: $world->schedule->trainingDays,
            awayTraining: ReviewPlanRebuild::away($reviewedRow)?->training, flaggedDeload: (bool) ($reviewedRow->is_deload ?? false),
            pausedWeeks: $reviewedCovering['pausedWeeks'],
        );
        $inReviewed = self::sessionsIn($sessionsByDay, $period->reviewed);
        $plannedByMuscle = [];
        foreach ($reviewedSessions->scheduled as $item) {
            foreach ($item->session->slots as $slot) {
                $plannedByMuscle[$slot->muscle->value] = ($plannedByMuscle[$slot->muscle->value] ?? 0) + $slot->sets;
            }
        }
        $reviewedIds = self::ids($inReviewed);
        $facts = new ReviewTrainingFacts(
            plannedSessions: count($reviewedSessions->scheduled),
            plannedSets: array_sum($plannedByMuscle),
            plannedSetsByMuscle: $plannedByMuscle,
            doneSessions: count(array_filter($inReviewed, fn (WorkoutSession $s) => self::isDone($s))),
            skippedSessions: count(array_filter($inReviewed, fn (WorkoutSession $s) => $s->is_skipped && ! self::isDone($s))),
            loggedSets: $data->sets->filter(fn (WorkoutSet $set) => $set->is_logged && ! $set->is_skipped
                && in_array(strtolower((string) $set->workout_session_id), $reviewedIds, true))->count(),
        );
        $ceilings = [];
        foreach (array_keys($plannedByMuscle) as $muscle) {
            $ceilings[$muscle] = VolumeProgression::landmarks(
                MuscleGroup::from($muscle), $domainProfile->experience, $reviewedCovering['mesocycle']->trainingDefaultsColumn(),
            )->mrv;
        }
        $pain = ReviewTraining::jointPainFlags(array_values($data->exerciseFeedback
            ->filter(fn (ExerciseFeedback $f) => in_array(strtolower((string) $f->workout_session_id), $reviewedIds, true))
            ->map(fn (ExerciseFeedback $f) => [
                'exerciseName' => $data->exercises->get($f->exercise_id)->name ?? 'Exercise',
                'level' => JointPain::tryFrom((string) $f->joint_pain) ?? JointPain::None,
            ])->all()));
        $nextSessions = WeekSessions::build(
            planWeek: $nextWeek, week: $nextIndex, mesocycle: $nextCovering['mesocycle'], mesocycleStartWeek: $nextCovering['startWeek'],
            trainingDays: [], awayTraining: $world->away?->training, flaggedDeload: $world->storedDeload, pausedWeeks: $world->pausedWeeks,
        );
        $kind = ReviewTraining::nextWeekKind(
            $nextSessions->mesocycleWeek === null ? null : $nextSessions->mesocycleWeek + 1,
            $nextSessions->mesocycleWeek === null ? null : $nextCovering['mesocycle']->weeks,
            $nextSessions->isDeload,
        );
        $training = ReviewTraining::summary(
            $facts, self::underperforming($logged, $period->reviewed), $ceilings, $pain, $kind,
        );

        // Step 4, progress.
        $progress = $phase === null ? null : ReviewProgressRules::make($phase, $trend, $period->reviewed->end() ?? $period->reviewed->start);

        // Step 6, the calorie rules' inputs. REV-040: a week's training fatigue, as the fatigue rules count it, worked
        // out once per week for both the calorie weeks and the phase's fatigue weeks.
        $fatigueByWeek = [];
        $fatigueWeek = function (DomainPlanWeek $week) use (&$fatigueByWeek, $data, $logged, $sessionsByDay, $feedbackBySession): FatigueWeek {
            $key = implode('|', [$week->start->format('Y-m-d'), $week->dayCount, (int) $week->isDeload, (int) $week->isDietBreak, (int) $week->isAway]);

            return $fatigueByWeek[$key] ??= self::fatigueWeek($data, $logged, $sessionsByDay, $feedbackBySession, $week);
        };
        $weeks = $context->reviewWeeks(
            $reviewedIndex, ReviewData::MEAL_WEEKS, $fallback, $data->meals,
            fn (DomainPlanWeek $week): bool => Fatigue::training($fatigueWeek($week))->isHigh(),
        );
        $repeatKcal = RepeatThisWeek::targetKcal($weeks);

        $recommendation = null;
        $last = $weeks === [] ? null : $weeks[count($weeks) - 1];
        if ($mode === ReviewMode::Full && $phase !== null && $last !== null && $last->week->start == $reviewedWeek->start) {
            $days = array_map(fn (DateTimeImmutable $day) => $day->format('Y-m-d'), $reviewedDays);
            $current = new WeekTargets(
                days: $data->dayTargets
                    ->filter(fn (DayTarget $t) => in_array(TrainSnapshot::day($t->date)->format('Y-m-d'), $days, true))
                    ->map(fn (DayTarget $t) => new DomainDayTarget(
                        type: $t->day_type ?? DayType::Rest, isCompleted: false, proteinG: $t->protein_g, fatG: $t->fat_g,
                        carbsG: $t->carbs_g, carbBoundsWarning: null,
                    ))->values()->all(),
                leanMassKg: MacroTargets::leanMassKg($domainProfile),
                mealsPerDay: (int) $profile->meal_count,
            );
            $reviewedEnd = $reviewedWeek->end() ?? $reviewedWeek->start;
            $weekProfile = ReviewPlanRebuild::domainProfile($profile, CurrentWeight::kg($trend->trendKg($reviewedEnd), $trend->currentKg()));
            $maintenance = self::built($world, NextWeekCalories::dietBreak());
            $recommendation = CalorieReview::recommend(
                phase: $phase,
                weeks: $weeks,
                trend: $trend,
                currentTargets: $current,
                profile: $weekProfile,
                formulaExpenditureKcal: ($maintenance === null ? null : BuiltWeekSummary::averageKcal($maintenance)) ?? $current->dailyAverageKcal(),
                plannedDailySteps: StepPlanUpdate::plannedDailySteps(self::stepPlan($data)),
            );
        }

        // Fatigue (REV-040 to REV-044): the phase's finished weeks, oldest first.
        $fatigueWeeks = [];
        $range = $phase === null ? null : PlanRules::weeks($phase, $context->grid);
        if ($range !== null) {
            for ($index = max(0, $range->lower); $index <= $reviewedIndex; $index++) {
                $fatigueWeeks[] = $fatigueWeek($flagged($index));
            }
        }
        $weeksSinceBreak = 0;
        for ($i = count($fatigueWeeks) - 1; $i >= 0 && ! $fatigueWeeks[$i]->isBreak(); $i--) {
            $weeksSinceBreak++;
        }
        $fatigue = $mode === ReviewMode::Full && $phase !== null ? Fatigue::advice($phase->type, $fatigueWeeks, $nextWeek) : null;

        // REV-011: the deload moves inside one mesocycle, and only when next week is still part of it.
        $coveringReviewed = $reviewedCovering['mesocycle']->weeks > 0 ? $reviewedCovering['record'] : null;
        $coveringNext = $nextCovering['mesocycle']->weeks > 0 ? $nextCovering['record'] : null;
        $sameMesocycle = $coveringReviewed !== null && $coveringReviewed->id === $coveringNext?->id;

        return new self(
            mode: $mode,
            period: $period,
            reviewed: $reviewedWeek,
            phase: $phase,
            world: $world,
            weighInRows: $weighInRows,
            meals: $meals,
            training: $training,
            progress: $progress,
            weeks: $weeks,
            recommendation: $recommendation,
            fatigue: $fatigue,
            weeksSinceBreak: $weeksSinceBreak,
            stepOffer: StepPlanCheck::offerForWeek(self::stepCounts($data), $reviewedDays, self::stepPlan($data)),
            repeatKcal: $repeatKcal,
            plannedNextWeek: $planned,
            options: $phase === null ? null : CalorieOptions::options($phase->type, $reviewedWeek, $nextWeek),
            deload: $sameMesocycle ? DeloadMove::plan(
                mesocycleWeeks: (int) $coveringReviewed->weeks, mesocycleStartWeek: $reviewedCovering['startWeek'],
                reviewedWeek: $reviewedIndex, nextWeek: $nextIndex, nextIsDeload: $nextWeek->isDeload,
            ) : DeloadPlan::dietBreakOnly(),
            deloadMesocycleID: $sameMesocycle ? (string) $coveringReviewed->id : null,
            today: $data->today,
            weightUnit: WeightUnit::tryFrom((string) $profile->weight_unit) ?? WeightUnit::Kg,
        );
    }

    // MARK: pieces

    /** Next week built for a calorie choice; null when the rules refuse it. */
    private static function built(ReviewWorld $world, NextWeekCalories $calories): ?BuiltWeek
    {
        try {
            return ReviewNextWeek::build($world, $calories);
        } catch (WeekBuildRefusal) {
            return null;
        }
    }

    /**
     * REV-003 step 2, REV-006: the reviewed week's planned meals and each one's check-in against its target (MEAL-047).
     *
     * @param  list<DateTimeImmutable>  $days
     */
    private static function mealsSummary(ReviewData $data, array $days): ReviewMealsSummary
    {
        $eaten = array_column($data->meals, 'eaten', 'id');
        $fit = new MealFit;
        $items = [];
        foreach ($days as $day) {
            $target = $data->dayTargets->first(fn (DayTarget $t) => TrainSnapshot::day($t->date) == $day);
            if ($target === null) {
                continue;
            }
            foreach (array_values(TodaySnapshot::json($target->meals)) as $index => $meal) {
                $id = strtolower((string) ($meal['id'] ?? ''));
                $items[] = new ReviewMealItem(
                    id: $id,
                    day: $day,
                    name: ($meal['name'] ?? null) ?: 'Meal '.($index + 1),
                    checkIn: MealCheckInState::of($eaten[$id] ?? null, new MealTarget(
                        (float) ($meal['kcal'] ?? 0), (float) ($meal['protein_g'] ?? 0), (float) ($meal['fat_g'] ?? 0), (float) ($meal['carbs_g'] ?? 0),
                    ), $fit),
                );
            }
        }

        return new ReviewMealsSummary($items);
    }

    /**
     * A week as the fatigue rules read it (REV-040).
     *
     * @param  list<array{date: DateTimeImmutable, day: string, exercises: list<LoggedExercise>}>  $logged
     * @param  array<string, list<WorkoutSession>>  $sessionsByDay
     * @param  array<string, list<MuscleFeedback>>  $feedbackBySession
     */
    private static function fatigueWeek(
        ReviewData $data, array $logged, array $sessionsByDay, array $feedbackBySession, DomainPlanWeek $week,
    ): FatigueWeek {
        $inWeek = self::sessionsIn($sessionsByDay, $week);
        $feedback = [];
        foreach (self::ids($inWeek) as $id) {
            foreach ($feedbackBySession[$id] ?? [] as $f) {
                $feedback[] = new FeedbackAnswers(
                    soreness: Soreness::tryFrom((string) $f->soreness),
                    pump: Pump::tryFrom((string) $f->pump),
                    volume: VolumeFeedback::tryFrom((string) $f->volume),
                );
            }
        }
        $days = array_map(fn (DateTimeImmutable $day) => $day->format('Y-m-d'), $week->days());
        $loggedDays = array_unique(array_column(
            array_filter($data->meals, fn (array $meal) => $meal['checkInKcal'] !== null && in_array($meal['date'], $days, true)),
            'date',
        ));

        return new FatigueWeek(
            week: $week,
            underperformingMuscles: self::underperforming($logged, $week),
            feedback: $feedback,
            skippedSessions: count(array_filter($inWeek, fn (WorkoutSession $s) => $s->is_skipped && ! self::isDone($s))),
            loggedDays: count($loggedDays),
        );
    }

    /**
     * Every session by the Y-m-d of the day it ran, or was planned for; a session with neither is on no day.
     *
     * @return array<string, list<WorkoutSession>>
     */
    private static function sessionsByDay(ReviewData $data): array
    {
        $byDay = [];
        foreach ($data->sessions as $session) {
            $date = TodaySnapshot::instant($session->actual_start_at ?? $session->planned_start_at);
            if ($date !== null) {
                $byDay[TrainSnapshot::day($date)->format('Y-m-d')][] = $session;
            }
        }

        return $byDay;
    }

    /**
     * The sessions that belong to a plan week: by the day they ran, or were planned for.
     *
     * @param  array<string, list<WorkoutSession>>  $sessionsByDay
     * @return list<WorkoutSession>
     */
    private static function sessionsIn(array $sessionsByDay, DomainPlanWeek $week): array
    {
        $sessions = [];
        foreach ($week->days() as $day) {
            array_push($sessions, ...($sessionsByDay[$day->format('Y-m-d')] ?? []));
        }

        return $sessions;
    }

    /**
     * @param  list<WorkoutSession>  $sessions
     * @return list<string> their ids, lower case
     */
    private static function ids(array $sessions): array
    {
        return array_map(fn (WorkoutSession $s) => strtolower((string) $s->id), $sessions);
    }

    /** Finished: started and ended, and not skipped. */
    private static function isDone(WorkoutSession $session): bool
    {
        return ! $session->is_skipped && $session->actual_start_at !== null && $session->actual_end_at !== null;
    }

    /**
     * Every finished session with its logged working sets, oldest first (PROG-050).
     *
     * @return list<array{date: DateTimeImmutable, day: string, exercises: list<LoggedExercise>}>
     */
    private static function loggedSessions(ReviewData $data): array
    {
        $setsBySession = $data->sets->filter(fn (WorkoutSet $set) => $set->is_logged && ! $set->is_skipped)
            ->groupBy(fn (WorkoutSet $set) => strtolower((string) $set->workout_session_id));
        $sessions = [];
        foreach ($data->sessions as $session) {
            $date = TodaySnapshot::instant($session->actual_start_at);
            if (! self::isDone($session) || $date === null) {
                continue;
            }
            $sets = ($setsBySession->get(strtolower((string) $session->id)) ?? collect())->sortBy('position')->values();
            $order = [];
            $byExercise = [];
            foreach ($sets as $set) {
                if ($set->exercise_id === null || $set->target_reps === null || $set->actual_reps === null) {
                    continue;
                }
                if (! isset($byExercise[$set->exercise_id])) {
                    $order[] = $set->exercise_id;
                }
                $byExercise[$set->exercise_id][] = new LoggedSet((float) ($set->actual_weight_kg ?? 0), $set->target_reps, $set->actual_reps);
            }
            $exercises = [];
            foreach ($order as $id) {
                /** @var ?Exercise $exercise */
                $exercise = $data->exercises->get($id);
                if ($exercise !== null) {
                    $exercises[] = new LoggedExercise($exercise->name, $exercise->muscle_group, new ClosedRange(0, 0), $byExercise[$id]);
                }
            }
            $sessions[] = ['date' => $date, 'day' => TrainSnapshot::day($date)->format('Y-m-d'), 'exercises' => $exercises];
        }
        usort($sessions, fn (array $a, array $b) => $a['date'] <=> $b['date']);

        return $sessions;
    }

    /**
     * PROG-052: the muscles a session of this week flagged.
     *
     * @param  list<array{date: DateTimeImmutable, day: string, exercises: list<LoggedExercise>}>  $sessions
     * @return list<MuscleGroup>
     */
    private static function underperforming(array $sessions, DomainPlanWeek $week): array
    {
        $end = $week->end();
        if ($end === null) {
            return [];
        }
        $after = $end->modify('+1 day');
        $upToWeek = array_values(array_filter($sessions, fn (array $s) => $s['date'] < $after));
        $days = array_map(fn (DateTimeImmutable $day) => $day->format('Y-m-d'), $week->days());
        $inWeek = count(array_filter($upToWeek, fn (array $s) => in_array($s['day'], $days, true)));
        if ($inWeek === 0) {
            return [];
        }

        return Underperformance::flaggedMusclesThisWeek(array_column($upToWeek, 'exercises'), $inWeek);
    }

    /** @return list<StepPlanDay> the profile's step plan, by ISO weekday */
    private static function stepPlan(ReviewData $data): array
    {
        return array_map(
            fn (array $day) => new StepPlanDay((int) $day['weekday'], (int) $day['min'], (int) $day['max']),
            array_values(TodaySnapshot::json($data->profile?->step_plan)),
        );
    }

    /** @return array<int, int> REV-050: the stored step counts by the Unix time of their day's start */
    private static function stepCounts(ReviewData $data): array
    {
        $counts = [];
        foreach ($data->steps as $step) {
            /** @var Step $step */
            $counts[TrainSnapshot::day($step->date)->getTimestamp()] = (int) $step->count;
        }

        return $counts;
    }
}
