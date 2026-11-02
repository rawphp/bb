<?php

namespace App\Domain\WeekBuilder;

use App\Domain\Energy\EnergyRules;
use App\Domain\Energy\EnergyWorkout;
use App\Domain\Macros\MacroTargets;
use App\Domain\Macros\MoveRefusal;
use App\Domain\Macros\PlannedDay;
use App\Domain\Macros\TargetRefusal;
use App\Domain\MealSplit\DayEntry;
use App\Domain\MealSplit\MealSplit;
use App\Domain\Model\Day;
use App\Domain\Model\DayType;
use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\Meal;
use App\Domain\Model\MealTarget;
use App\Domain\Model\Mesocycle;
use App\Domain\Model\Plan;
use App\Domain\Model\PlanWeek;
use App\Domain\Model\Profile;
use App\Domain\Model\StepPlanDay;
use App\Domain\Placement\ClockRange;
use App\Domain\Placement\DayPlacement;
use App\Domain\Placement\MealPlacement;
use App\Domain\Placement\MealRole;
use App\Domain\Placement\MealSlot;
use App\Domain\Placement\PlacedMeal;
use App\Domain\Placement\ScheduledSession;
use App\Domain\Placement\WorkoutTime;
use App\Domain\Plan\DeloadCalories;
use App\Domain\Plan\PlanRules;
use App\Domain\Plan\PlanWeekGrid;
use App\Domain\Retarget\OtherActivity;
use App\Domain\WeekBuilder\AwayWeek\Calories;
use App\Domain\WeekBuilder\WeekSessions\SessionRow;
use Closure;
use DateTimeImmutable;

/**
 * Builds a plan week end to end: day types, day targets, meal times and meal targets, and workouts on the right days.
 * The one call the Today screen and onboarding make; it composes the energy, macro, split and placement rules and
 * holds none of its own. Days are read in the time zone of the plan start.
 *
 * The rules never make an id: each meal they create takes the next id from $newId.
 */
final class WeekBuilder
{
    /**
     * @param  Profile  $profile  weightKg should be the trend weight (WT-011), as MacroTargets documents.
     * @param  int  $mesocycleStartWeek  The plan week the mesocycle's first week falls on.
     * @param  int  $week  The plan week index to build.
     * @param  Closure(): string  $newId  An id for each meal the week places.
     * @param  bool  $isDietBreak  An unplanned recovery diet break (PLAN-025): the week runs at maintenance.
     * @param  bool  $flaggedDeload  The stored plan week is flagged a deload (PLAN-022), whatever its mesocycle says.
     * @param  bool  $dietBreakTurnedOff  PLAN-023: the user turned off the diet break of this week. A Fat loss or
     *                                    Mini-cut deload then runs in a deficit; an unplanned break ($isDietBreak) or an
     *                                    away week still runs at maintenance.
     * @param  float  $calorieChangePerDay  Moves every day's calories by this much from what the rules give, through
     *                                      WeekTargets::adjusted(): a chosen average in onboarding (ONB-017). The meals
     *                                      are split from the changed day.
     * @param  list<CalorieMove>  $moves  Calories moved between days after that change (NUT-021, REV-004).
     * @param  list<int>  $pausedWeeks
     * @param  list<SessionRow>  $sessionRows  The mesocycle's stored session rows, which move, re-time or skip its
     *                                         sessions (SCH-050 to SCH-057).
     * @param  array<string, OtherActivity>  $otherActivities  The stored Other activities (SCH-032) by their day,
     *                                                         "Y-m-d" in the plan's time zone. Each one's energy joins
     *                                                         its day's and raises the day type (SCH-038); meal
     *                                                         placement, the shake and WeekDay::$workouts stay with the
     *                                                         mesocycle session only.
     *
     * @throws WeekBuildRefusal
     */
    public static function build(
        Plan $plan,
        Profile $profile,
        Mesocycle $mesocycle,
        int $mesocycleStartWeek,
        int $week,
        WeekSchedule $schedule,
        Closure $newId,
        ?AwayWeek $away = null,
        bool $isDietBreak = false,
        bool $flaggedDeload = false,
        bool $dietBreakTurnedOff = false,
        float $calorieChangePerDay = 0,
        array $moves = [],
        array $pausedWeeks = [],
        array $sessionRows = [],
        array $otherActivities = [],
    ): BuiltWeek {
        $grid = PlanWeekGrid::forPlan($plan);
        $gridWeek = $grid->planWeek($week);
        $dates = $gridWeek->days();
        $zone = $plan->start->getTimezone();

        // PLAN-002, NUT-011: the phase covering the week, and where the week sits in it.
        $phaseIndex = PlanRules::phaseIndexCoveringWeek($week, $plan, $grid);
        $phaseWeeks = $phaseIndex === null ? null : PlanRules::weeks($plan->phases[$phaseIndex], $grid);
        if ($phaseIndex === null || $phaseWeeks === null) {
            throw WeekBuildRefusal::noPhase();
        }
        $phase = $plan->phases[$phaseIndex];
        $previousPhaseType = $phaseIndex > 0 ? $plan->phases[$phaseIndex - 1]->type : null;
        $weekOfPhase = $week - $phaseWeeks->lower + 1;

        // PLAN-022, PLAN-024, SCH-060, SCH-039, SCH-040: the week's deload flag and its sessions on their days.
        $weekSessions = WeekSessions::build(
            planWeek: $gridWeek, week: $week, mesocycle: $mesocycle, mesocycleStartWeek: $mesocycleStartWeek,
            trainingDays: $schedule->trainingDays, awayTraining: $away?->training, flaggedDeload: $flaggedDeload,
            pausedWeeks: $pausedWeeks, rows: $sessionRows,
        );
        $isDeload = $weekSessions->isDeload;
        // PLAN-022 to PLAN-024: every deload runs at maintenance, but a cut's can be turned off (PLAN-023).
        $deloadAtMaintenance = $isDeload && DeloadCalories::runsAtMaintenance($phase->type, $dietBreakTurnedOff);
        $atMaintenance = $deloadAtMaintenance || $isDietBreak || $away?->calories === Calories::Maintenance;
        $planWeek = new PlanWeek(
            $gridWeek->start, $gridWeek->dayCount, isDeload: $isDeload,
            isDietBreak: $isDietBreak || ($phase->type->isFatLoss() && $atMaintenance), isAway: $away !== null,
        );

        $workouts = array_map(
            // SCH-053: a skipped session trains nothing.
            fn (DateTimeImmutable $date) => array_values(array_map(
                fn (ScheduledSession $item) => self::workout($item, $isDeload),
                array_filter(
                    $weekSessions->scheduled,
                    fn (ScheduledSession $item) => ! $item->isSkipped && $item->date->format('Y-m-d') === $date->format('Y-m-d'),
                ),
            )),
            $dates,
        );
        // SCH-038: an Other activity adds its energy to the day's; it places no meals.
        $weightKg = $profile->weightKg;
        $dayWorkouts = array_map(function (int $index) use ($workouts, $dates, $otherActivities, $weightKg) {
            $energy = array_map(fn (WeekWorkout $workout) => new EnergyWorkout((float) $workout->time->lengthMinutes, $workout->intensity), $workouts[$index]);
            $activity = $otherActivities[$dates[$index]->format('Y-m-d')] ?? null;
            if ($activity !== null) {
                $energy[] = new EnergyWorkout((float) $activity->minutes, $activity->intensity);
            }

            return EnergyRules::dayWorkout($energy, $weightKg);
        }, array_keys($dates));

        // SAFE-003: training-only mode stops with the workouts.
        $nutrition = $schedule->nutrition;
        if ($nutrition === null) {
            $days = array_map(
                fn (int $index) => new WeekDay(new Day($dates[$index], $dayWorkouts[$index]->dayType), $workouts[$index], [], null, null),
                array_keys($dates),
            );

            return new BuiltWeek($planWeek, $phase, $days);
        }

        // NUT-002, NUT-003, SCH-060: each day's expenditure; rest-day calories leave the workout energy out.
        $restDayCalories = $away?->calories === Calories::RestDays;
        $planned = array_map(function (int $index) use ($dates, $nutrition, $profile, $weightKg, $zone, $restDayCalories, $dayWorkouts) {
            $weekday = (int) $dates[$index]->format('N');
            $steps = array_find($nutrition->steps, fn (StepPlanDay $day) => $day->weekday === $weekday);
            $factor = $steps === null ? EnergyRules::activityFactor(0) : EnergyRules::activityFactorForDay($steps);
            $expenditure = EnergyRules::dailyExpenditure(
                EnergyRules::bmr($profile, $weightKg, $dates[$index], $zone), $factor,
                $restDayCalories ? 0 : $dayWorkouts[$index]->kcal,
            );

            return new PlannedDay($expenditure, $restDayCalories ? DayType::Rest : $dayWorkouts[$index]->dayType);
        }, array_keys($dates));

        // NUT-007, NUT-023: a maintenance week has no daily change, whatever the phase. PLAN-023: a deload with its
        // diet break turned off still keeps the phase's rate.
        $targetWeek = new PlanWeek(
            $planWeek->start, $planWeek->dayCount, isDeload: $isDeload && $deloadAtMaintenance,
            isDietBreak: $atMaintenance, isAway: $planWeek->isAway,
        );
        try {
            $targets = MacroTargets::week(
                profile: $profile, phase: $phase, week: $targetWeek, days: $planned, mealsPerDay: $nutrition->mealsPerDay,
                previousPhaseType: $previousPhaseType, weekOfPhase: $weekOfPhase,
            )->adjusted($calorieChangePerDay, $profile);
        } catch (TargetRefusal $refusal) {
            throw WeekBuildRefusal::targets($refusal);
        }
        try {
            foreach ($moves as $move) {
                $targets = $targets->moving($move->kcal, $move->from, $move->to);
            }
        } catch (MoveRefusal $refusal) {
            throw WeekBuildRefusal::move($refusal);
        }

        // SCH-041 to SCH-045, NUT-030 to NUT-037: meal times, then meal targets.
        $days = [];
        foreach ($dates as $index => $date) {
            $workout = ($workouts[$index][0] ?? null)?->time;
            $slots = [];
            for ($meal = 0; $meal < $nutrition->mealsPerDay; $meal++) {
                $slots[] = new MealSlot($newId());
            }
            $placement = MealPlacement::place(
                $slots, $nutrition->eatingWindow, $nutrition->busyOnWeekday((int) $date->format('N')), $workout,
            );
            if ($placement === null) {
                throw WeekBuildRefusal::mealsDoNotFit($index);
            }

            $dayTarget = $targets->days[$index];
            $total = new MealTarget($dayTarget->kcal(), $dayTarget->proteinG, $dayTarget->fatG, $dayTarget->carbsG);
            [$entries, $meals] = self::layout($placement, $workout, $nutrition->eatingWindow, $newId);
            $split = [];
            foreach (MealSplit::split($total, $entries, $nutrition->carbPlacement) as $item) {
                $split[$item->id] = $item->target;
            }
            $meals = array_map(
                fn (WeekMeal $meal) => new WeekMeal(
                    new Meal($meal->meal->id, $meal->meal->name, $split[$meal->id()] ?? $meal->meal->target), $meal->time, $meal->kind,
                ),
                $meals,
            );
            $days[] = new WeekDay(
                new Day($date, $dayWorkouts[$index]->dayType), $workouts[$index], $meals, $total, $dayTarget->carbBoundsWarning,
            );
        }

        return new BuiltWeek($planWeek, $phase, $days);
    }

    /** A scheduled session as the day's workout: its length from its working sets (SCH-046), its intensity (TBL-031). */
    private static function workout(ScheduledSession $item, bool $isDeload): WeekWorkout
    {
        $sets = array_sum(array_map(fn (ExerciseSlot $slot) => $slot->sets, $item->session->slots));
        $minutes = EnergyRules::sessionMinutes($sets);

        return new WeekWorkout(
            $item->session, new WorkoutTime($item->time->start, $minutes), $sets,
            EnergyRules::sessionIntensity($sets, (float) $minutes, $isDeload),
        );
    }

    /**
     * The day's meals in time order with the workout (and its shake) among them, as the split wants them.
     *
     * @param  Closure(): string  $newId
     * @return array{list<DayEntry>, list<WeekMeal>}
     */
    private static function layout(DayPlacement $placement, ?WorkoutTime $workout, ClockRange $window, Closure $newId): array
    {
        $placed = $placement->meals;
        $empty = new MealTarget(kcal: 0, proteinG: 0, fatG: 0, carbsG: 0);
        $entries = array_map(fn (PlacedMeal $meal) => DayEntry::meal($meal->id), $placed);
        $meals = array_map(
            fn (PlacedMeal $meal, int $index) => new WeekMeal(
                new Meal($meal->id, 'Meal '.($index + 1), $empty), $meal->time, self::kind($meal->role),
            ),
            $placed,
            array_keys($placed),
        );
        if ($workout === null) {
            return [$entries, $meals];
        }

        // The workout sits between the pre- and post-workout meals; with neither, by the clock, on whichever day puts
        // it nearer the window, as the meal placement does.
        $post = array_find_key($placed, fn (PlacedMeal $meal) => $meal->role === MealRole::PostWorkout);
        $pre = array_find_key($placed, fn (PlacedMeal $meal) => $meal->role === MealRole::PreWorkout);
        if ($post !== null) {
            $position = $post;
        } elseif ($pre !== null) {
            $position = $pre + 1;
        } else {
            $offset = $window->offset($workout->start);
            $position = array_find_key($placed, fn (PlacedMeal $meal) => $window->start->minutesUntil($meal->time) > $offset)
                ?? count($placed);
        }
        array_splice($entries, $position, 0, [DayEntry::workout()]);
        if ($placement->shake !== null) {
            $id = $newId();
            array_splice($entries, $position + 1, 0, [DayEntry::intraWorkoutShake($id)]);
            array_splice($meals, $position, 0, [
                new WeekMeal(new Meal($id, MealNames::INTRA_WORKOUT_SHAKE, $empty), $placement->shake, WeekMealKind::IntraWorkoutShake),
            ]);
        }

        return [$entries, $meals];
    }

    private static function kind(MealRole $role): WeekMealKind
    {
        return match ($role) {
            MealRole::Regular => WeekMealKind::Regular,
            MealRole::PreWorkout => WeekMealKind::PreWorkout,
            MealRole::PostWorkout => WeekMealKind::PostWorkout,
        };
    }
}
