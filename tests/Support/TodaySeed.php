<?php

namespace Tests\Support;

use App\Domain\Model\MuscleGroup;
use App\Models\DayTarget;
use App\Models\Food;
use App\Models\MealCheckIn;
use App\Models\Mesocycle;
use App\Models\OffPlanEntry;
use App\Models\OtherActivity;
use App\Models\Plan;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\PlanWeek;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\Step;
use App\Models\User;
use App\Models\WeighIn;
use App\Models\WeighInScheduleEntry;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;

/**
 * A user part way through a plan, stored, for the HTTP seam of today_show and week_show. The plan starts Saturday
 * 2026-09-12 (review day Saturday); a 4-week mesocycle runs from it, training Monday, Wednesday and Friday at 17:30;
 * the plan week from 2026-10-03 is the deload. Every day from the start to 2026-10-09 has a target with 3 meals.
 */
final class TodaySeed
{
    public static function user(): User
    {
        $user = User::factory()->create();
        Plan::factory()->for($user)->create(['start_date' => '2026-09-12', 'review_weekday' => 6]);
        Profile::factory()->for($user)->create([
            'experience' => 'intermediate',
            'weight_unit' => 'kg',
            'consumed_totals' => 'planned',
            'eating_window' => ['start' => '08:00', 'end' => '20:00'],
            'meal_count' => 3,
            'training_days' => [
                ['weekday' => 1, 'start' => '17:30', 'minutes' => 60],
                ['weekday' => 3, 'start' => '17:30', 'minutes' => 60],
                ['weekday' => 5, 'start' => '17:30', 'minutes' => 60],
            ],
            'busy_periods' => [['label' => 'Team meeting', 'start' => '09:00', 'end' => '10:30', 'weekdays' => [1, 2, 3, 4, 5]]],
            'step_plan' => [['weekday' => 3, 'min' => 6000, 'max' => 8000]],
        ]);
        PlanWeek::factory()->for($user)->create(['start_date' => '2026-10-03', 'is_deload' => true, 'is_diet_break' => false, 'is_away' => false]);
        WeighInScheduleEntry::factory()->for($user)->create(['weekdays' => [3, 6], 'time' => '07:00']);

        return $user;
    }

    /**
     * Another user whose rows differ from TodaySeed::user()'s in everything Today shows: a plan from Monday 2026-09-07
     * with Monday reviews, other training days, busy periods, steps and weigh-ins, away weeks, a phase, targets
     * of 3000 kcal to 2026-11-30, and a logged Wednesday. Made first, so an unscoped `first()` would find it.
     */
    public static function stranger(): User
    {
        $user = User::factory()->create();
        Plan::factory()->for($user)->create(['start_date' => '2026-09-07', 'review_weekday' => 1]);
        Profile::factory()->for($user)->create([
            'experience' => 'intermediate',
            'weight_unit' => 'lb',
            'eating_window' => ['start' => '06:00', 'end' => '23:00'],
            'meal_count' => 3,
            'training_days' => [['weekday' => 2, 'start' => '06:00', 'minutes' => 60], ['weekday' => 3, 'start' => '06:00', 'minutes' => 60]],
            'busy_periods' => [['label' => 'School run', 'start' => '08:00', 'end' => '09:00', 'weekdays' => [1, 2, 3, 4, 5]]],
            'step_plan' => [['weekday' => 3, 'min' => 10000, 'max' => 12000]],
        ]);
        PlanWeek::factory()->for($user)->create(['start_date' => '2026-09-21', 'is_deload' => true, 'is_diet_break' => false, 'is_away' => true, 'away_training' => 'deload']);
        // On the Saturday grid of TodaySeed::user(): leaked, it would pause that user's week 2.
        PlanWeek::factory()->for($user)->create(['start_date' => '2026-09-19', 'is_deload' => false, 'is_diet_break' => false, 'is_away' => true, 'away_training' => 'pause']);
        WeighInScheduleEntry::factory()->for($user)->create(['weekdays' => [3], 'time' => '06:15']);
        TrainSeed::phase($user, 'fatLoss', '2026-09-07');
        self::targets($user, last: '2026-11-30', kcal: 3000);
        self::wednesday($user, self::mesocycle($user));

        return $user;
    }

    public static function mesocycle(User $user): Mesocycle
    {
        $bench = TrainSeed::exercise('Bench Press', MuscleGroup::Chest);
        $squat = TrainSeed::exercise('Back Squat', MuscleGroup::Quads);

        return Mesocycle::factory()->for($user)->create([
            'name' => 'Hypertrophy block',
            'weeks' => 4,
            'start_date' => '2026-09-12',
            'days' => [
                ['id' => '01JB6Q400A2B3C4D5E6F7G8H9J', 'label' => 'Upper A', 'weekday' => null, 'slots' => [
                    ['id' => '01JB6Q401A2B3C4D5E6F7G8H9J', 'exercise_id' => $bench->id, 'muscle_group' => 'chest', 'sets' => 4],
                ]],
                ['id' => '01JB6Q410A2B3C4D5E6F7G8H9J', 'label' => 'Lower A', 'weekday' => null, 'slots' => [
                    ['id' => '01JB6Q411A2B3C4D5E6F7G8H9J', 'exercise_id' => $squat->id, 'muscle_group' => 'quads', 'sets' => 4],
                    ['id' => '01JB6Q412A2B3C4D5E6F7G8H9J', 'exercise_id' => null, 'muscle_group' => 'calves', 'sets' => 3],
                ]],
                ['id' => '01JB6Q420A2B3C4D5E6F7G8H9J', 'label' => 'Upper B', 'weekday' => null, 'slots' => [
                    ['id' => '01JB6Q421A2B3C4D5E6F7G8H9J', 'exercise_id' => $bench->id, 'muscle_group' => 'chest', 'sets' => 3],
                ]],
            ],
        ]);
    }

    /** Targets with 3 meals on every day from the plan start to `$last`, of `$kcal` each. */
    public static function targets(User $user, string $last = '2026-10-09', float $kcal = 2600): void
    {
        for ($day = new \DateTimeImmutable('2026-09-12'); $day <= new \DateTimeImmutable($last); $day = $day->modify('+1 day')) {
            DayTarget::factory()->for($user)->create([
                'date' => $day->format('Y-m-d'), 'day_type' => 'hard', 'kcal' => $kcal, 'protein_g' => 170, 'fat_g' => 70, 'carbs_g' => 320,
                'meals' => [
                    ['name' => 'Breakfast', 'time' => '08:00', 'kcal' => 780, 'protein_g' => 51, 'fat_g' => 21, 'carbs_g' => 96],
                    ['name' => 'Lunch', 'time' => '12:30', 'kcal' => 780, 'protein_g' => 51, 'fat_g' => 21, 'carbs_g' => 96],
                    ['name' => 'Dinner', 'time' => '19:30', 'kcal' => 1040, 'protein_g' => 68, 'fat_g' => 28, 'carbs_g' => 128],
                ],
            ]);
        }
    }

    /** Wednesday 2026-09-23 as rp-clone's screenshot has it: planned breakfast, lunch eaten, a weigh-in, cake, tennis. */
    public static function wednesday(User $user, Mesocycle $mesocycle): void
    {
        $day = DayTarget::query()->where('user_id', $user->id)->whereDate('date', '2026-09-23')->firstOrFail();
        [$breakfast, $lunch, $dinner] = array_column($day->meals, 'id');
        $oats = Food::factory()->createOne(['name' => 'Oats', 'kcal' => 380, 'protein' => 13, 'fat' => 7, 'carbs' => 66]);
        PlannedFood::factory()->create(['meal_id' => $breakfast, 'food_id' => $oats->getKey(), 'grams' => 80]);
        MealCheckIn::factory()->create(['meal_id' => $lunch, 'time' => '12:40', 'kcal' => 790, 'protein_g' => 55, 'fat_g' => 20, 'carbs_g' => 100, 'is_manual' => false]);
        WeighIn::factory()->for($user)->create(['weight_kg' => 82.4, 'weighed_at' => '2026-09-23 07:05:00']);
        // Weights and steps on the days either side are not this day's.
        WeighIn::factory()->for($user)->create(['weight_kg' => 85.0, 'weighed_at' => '2026-09-22 23:59:00']);
        WeighIn::factory()->for($user)->create(['weight_kg' => 80.0, 'weighed_at' => '2026-09-24 00:00:00']);
        Step::factory()->for($user)->create(['date' => '2026-09-22', 'count' => 12000]);
        OffPlanEntry::factory()->for($user)->create(['date' => '2026-09-23', 'name' => 'Office cake', 'time' => '15:00', 'kcal' => 320, 'protein_g' => 4, 'fat_g' => 16, 'carbs_g' => 40]);
        OtherActivity::factory()->for($user)->create(['date' => '2026-09-23', 'start_time' => '18:45', 'minutes' => 45, 'name' => 'Tennis', 'intensity' => 'moderate']);
        Step::factory()->for($user)->create(['date' => '2026-09-23', 'count' => 5432]);
        $chili = Recipe::factory()->createOne(['name' => 'Chili', 'servings' => 4]);
        $beef = Food::factory()->createOne(['name' => 'Beef mince', 'kcal' => 250, 'protein' => 26, 'fat' => 15, 'carbs' => 0]);
        RecipeIngredient::factory()->create(['recipe_id' => $chili->getKey(), 'food_id' => $beef->getKey(), 'grams' => 400]);
        PlannedRecipe::factory()->create(['meal_id' => $dinner, 'recipe_id' => $chili->getKey(), 'servings' => 2]);
        $monday = WorkoutSession::factory()->for($user)->createOne([
            'mesocycle_id' => $mesocycle->id, 'week' => 2, 'day' => 1,
            'actual_start_at' => '2026-09-21 17:30:00', 'actual_end_at' => '2026-09-21 18:20:00', 'is_skipped' => false,
        ]);
        WorkoutSet::factory()->create(['workout_session_id' => $monday->id, 'position' => 1, 'is_logged' => true]);
    }
}
