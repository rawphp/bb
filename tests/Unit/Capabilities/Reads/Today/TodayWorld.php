<?php

namespace Tests\Unit\Capabilities\Reads\Today;

use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use App\Models\Exercise;
use App\Models\Food;
use App\Models\MealCheckIn;
use App\Models\MealPlanningState;
use App\Models\Mesocycle;
use App\Models\MuscleFeedback;
use App\Models\OffPlanEntry;
use App\Models\OtherActivity;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\PlanWeek;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\Step;
use App\Models\WeighIn;
use App\Models\WeighInScheduleEntry;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Today\TodaySnapshot;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;

/**
 * rp-clone's TodayFixtures, in memory:
 *
 * - The plan starts Saturday 2026-09-12 and its review day is Saturday (ISO 6).
 * - "Meso A" is a 4-week mesocycle from the plan start. Its days are Upper A, Lower A and Upper B.
 * - The user trains Monday, Wednesday and Friday at 17:30.
 * - Plan week 3 (from 2026-09-26) is an away week that keeps training, plan week 4 (from 2026-10-03) the deload.
 * - Day targets exist from 2026-09-12 to 2026-10-09, three meals each (30%, 30%, 40%).
 * - Today is Wednesday 2026-09-23.
 */
final class TodayWorld
{
    public const MESO = 'bbbbbbbb-0000-4000-8000-000000000001';

    public const PLAN_START = '2026-09-12';

    public const LAST_TARGET = '2026-10-09';

    public const TODAY = '2026-09-23';

    /**
     * @param  list<Mesocycle>|null  $mesocycles  Meso A when null
     * @param  list<PlanWeek>|null  $planWeeks
     * @param  list<Phase>  $phases  none by default
     * @param  list<WorkoutSession>|null  $sessions
     * @param  list<MuscleFeedback>  $feedback
     * @param  array<string, list<array{0: string, 1: float, 2?: bool, 3?: int}>>  $foods  planned foods by meal id: [name-key, grams, cooked, position]
     * @param  array<string, list<array{0: float, 1: int}>>  $recipes  planned chili by meal id: [servings, position]
     * @param  array<string, string>  $states  planning states by meal id
     * @param  list<OffPlanEntry>  $offPlan
     * @param  list<OtherActivity>  $activities
     * @param  array<string, mixed>  $profile  replaces profile attributes
     */
    public static function snapshot(
        string $today = self::TODAY,
        ?string $date = null,
        ?array $mesocycles = null,
        ?array $planWeeks = null,
        ?array $sessions = null,
        array $feedback = [],
        ?array $foods = null,
        array $recipes = [],
        array $states = [],
        array $offPlan = [],
        array $activities = [],
        array $profile = [],
        ?int $steps = null,
        bool $withPlan = true,
        array $phases = [],
        string $zone = 'UTC',
        string $nowTime = '10:00',
        array $mealTimes = [],
    ): TodaySnapshot {
        $train = new TrainSnapshot(
            today: TrainSnapshot::day($today),
            plan: $withPlan ? (new Plan)->forceFill(['user_id' => 1, 'start_date' => self::PLAN_START, 'review_weekday' => 6]) : null,
            profile: (new Profile)->forceFill(array_replace([
                'user_id' => 1,
                'experience' => 'intermediate',
                'weight_unit' => 'kg',
                'consumed_totals' => 'planned',
                'eating_window' => ['start' => '07:00', 'end' => '22:00'],
                'meal_count' => 3,
                'training_days' => [
                    ['weekday' => 1, 'start' => '17:30', 'minutes' => 75],
                    ['weekday' => 3, 'start' => '17:30', 'minutes' => 75],
                    ['weekday' => 5, 'start' => '17:30', 'minutes' => 75],
                ],
                'busy_periods' => [['label' => 'Team meeting', 'start' => '09:00', 'end' => '10:30', 'weekdays' => [1, 2, 3, 4, 5]]],
                'step_plan' => [['weekday' => 3, 'min' => 6000, 'max' => 8000], ['weekday' => 6, 'min' => 0, 'max' => 8000]],
            ], $profile)),
            mesocycles: new Collection($mesocycles ?? [self::mesocycle()]),
            phases: new Collection($phases),
            sessions: new Collection($sessions ?? self::sessions()),
            sets: new Collection(self::sets()),
            feedback: new Collection($feedback),
            planWeeks: new Collection($planWeeks ?? [self::planWeek('2026-09-26', away: true), self::planWeek('2026-10-03', deload: true)]),
            dayTargets: new Collection,
            exercises: new Collection(self::exercises()),
            zone: new DateTimeZone($zone),
        );
        $targets = array_map(fn (string $day) => self::dayTarget($day, $mealTimes), self::planDays());
        $meals = [];
        foreach ($targets as $target) {
            foreach ($target->meals as $index => $meal) {
                $meals[] = self::meal($target, $meal['id'], $index + 1, $foods ?? [self::mealId('2026-09-23', 1) => [['oats', 80], ['whey', 30]]], $recipes, $states);
            }
        }
        $day = TrainSnapshot::day($date ?? $today);

        return new TodaySnapshot(
            train: $train,
            date: $day,
            lastTarget: TrainSnapshot::day(self::LAST_TARGET),
            dayTargets: new Collection($targets),
            meals: new Collection($meals),
            offPlan: new Collection($offPlan),
            otherActivities: new Collection($activities),
            weighIns: new Collection($day->format('Y-m-d') === '2026-09-23' ? [
                (new WeighIn)->forceFill(['id' => 'w2', 'user_id' => 1, 'weight_kg' => 82.4, 'weighed_at' => '2026-09-23 07:05:00']),
            ] : []),
            weighInSchedule: new Collection([
                (new WeighInScheduleEntry)->forceFill(['id' => 1, 'user_id' => 1, 'weekdays' => [3], 'time' => '07:00']),
                (new WeighInScheduleEntry)->forceFill(['id' => 2, 'user_id' => 1, 'weekdays' => [6], 'time' => '07:30']),
            ]),
            steps: $steps === null ? null : (new Step)->forceFill(['id' => 's', 'user_id' => 1, 'date' => $day->format('Y-m-d'), 'count' => $steps]),
            nowTime: $nowTime,
        );
    }

    /** @return list<string> */
    public static function planDays(): array
    {
        $days = [];
        for ($day = new DateTimeImmutable(self::PLAN_START); $day <= new DateTimeImmutable(self::LAST_TARGET); $day = $day->modify('+1 day')) {
            $days[] = $day->format('Y-m-d');
        }

        return $days;
    }

    /** @return array{0: string, 1: float, 2: float, 3: float, 4: float} type, kcal, protein, fat, carbs */
    public static function spec(string $date): array
    {
        if ($date >= '2026-10-03') {
            return ['deload', 2000, 150, 60, 220];
        }

        return match ((int) (new DateTimeImmutable($date))->format('N')) {
            1, 5 => ['moderate', 2400, 170, 70, 280],
            3 => ['hard', 2600, 170, 70, 320],
            default => ['rest', 2200, 170, 70, 230],
        };
    }

    public static function mealId(string $date, int $index): string
    {
        return sprintf('%s-0000-4000-8000-%012d', str_replace('-', '', $date), $index);
    }

    /** @param  array<string, ?string>  $times  a meal's time by its name, in place of the usual one; null for none */
    public static function dayTarget(string $date, array $times = []): DayTarget
    {
        [$type, $kcal, $protein, $fat, $carbs] = self::spec($date);
        $share = fn (float $value, int $index) => $index === 3 ? $value - 2 * intdiv((int) $value * 3, 10) : intdiv((int) $value * 3, 10);
        $meals = [];
        foreach (array_replace(['Breakfast' => '08:00', 'Lunch' => '12:30', 'Dinner' => '19:30'], $times) as $name => $time) {
            $index = count($meals) + 1;
            $meals[] = [
                'id' => self::mealId($date, $index), 'name' => $name, 'time' => $time,
                'kcal' => $share($kcal, $index), 'protein_g' => $share($protein, $index),
                'fat_g' => $share($fat, $index), 'carbs_g' => $share($carbs, $index),
            ];
        }

        return (new DayTarget)->forceFill([
            'id' => "dt-{$date}", 'user_id' => 1, 'date' => $date, 'day_type' => $type, 'kcal' => $kcal,
            'protein_g' => $protein, 'fat_g' => $fat, 'carbs_g' => $carbs, 'meals' => $meals,
        ]);
    }

    /**
     * @param  array<string, list<array{0: string, 1: float, 2?: bool, 3?: int}>>  $foods
     * @param  array<string, list<array{0: float, 1: int}>>  $recipes
     * @param  array<string, string>  $states
     */
    private static function meal(DayTarget $target, string $id, int $index, array $foods, array $recipes, array $states): DayTargetMeal
    {
        $meal = (new DayTargetMeal)->forceFill(['id' => $id, 'user_id' => 1, 'day_target_id' => $target->id, 'position' => $index - 1]);
        $planned = [];
        foreach ($foods[$id] ?? [] as $position => $entry) {
            $planned[] = (new PlannedFood)->forceFill([
                'id' => count($planned) + 1, 'user_id' => 1, 'meal_id' => $id, 'food_id' => $entry[0], 'position' => $entry[3] ?? $position,
                'grams' => $entry[1], 'is_cooked' => $entry[2] ?? false, 'is_locked' => false, 'unit' => 'g',
            ])->setRelation('food', self::food($entry[0]));
        }
        $chili = array_map(fn (array $entry) => (new PlannedRecipe)->forceFill([
            'id' => "pr-{$id}-{$entry[1]}", 'user_id' => 1, 'meal_id' => $id, 'recipe_id' => 'chili', 'position' => $entry[1],
            'servings' => $entry[0], 'is_locked' => false, 'recipe' => ['id' => 'chili', 'name' => 'Chili', 'servings' => 4],
        ])->setRelation('sourceRecipe', self::chili()), $recipes[$id] ?? []);
        $checkIn = self::checkIn(substr((string) $target->date, 0, 10), $index, $target->meals[$index - 1]);
        $state = $states[$id] ?? null;

        return $meal->setRelation('plannedFoods', new Collection($planned))
            ->setRelation('plannedRecipes', new Collection($chili))
            ->setRelation('checkIn', $checkIn)
            ->setRelation('planningState', $state === null ? null : (new MealPlanningState)->forceFill(['meal_id' => $id, 'user_id' => 1, 'state' => $state]));
    }

    public static function food(string $key): Food
    {
        [$name, $kcal, $protein, $fat, $carbs] = match ($key) {
            'oats' => ['Oats', 380, 13, 7, 66],
            'whey' => ['Whey', 400, 80, 6, 8],
            // 100 g come to exactly Wednesday breakfast's targets.
            'fit' => ['Perfect mix', 780, 51, 21, 96],
            // Weighed cooked, 2.5 g of cooked rice is 1 g raw.
            'rice' => ['Rice', 360, 7, 1, 80],
            'beef' => ['Beef mince', 250, 26, 15, 0],
            'beans' => ['Kidney beans', 100, 7, 0.5, 17],
        };

        return (new Food)->forceFill([
            'id' => $key, 'name' => $name, 'kcal' => $kcal, 'protein' => $protein, 'fat' => $fat, 'carbs' => $carbs,
            'cooked_ratio' => $key === 'rice' ? 2.5 : null,
        ]);
    }

    /** RCP-001: 4 servings of chili from 400 g of beef and 400 g of beans: 350 kcal, 33 g protein, 15.5 g fat, 17 g carbs each. */
    public static function chili(): Recipe
    {
        $ingredients = array_map(fn (array $row) => (new RecipeIngredient)->forceFill([
            'id' => $row[1], 'recipe_id' => 'chili', 'food_id' => $row[0], 'position' => $row[1], 'grams' => 400, 'is_cooked' => false,
        ])->setRelation('food', self::food($row[0])), [['beef', 0], ['beans', 1]]);

        return (new Recipe)->forceFill(['id' => 'chili', 'name' => 'Chili', 'servings' => 4])
            ->setRelation('ingredients', new Collection($ingredients));
    }

    /** @param  array<string, mixed>  $meal */
    private static function checkIn(string $date, int $index, array $meal): ?MealCheckIn
    {
        $values = match (true) {
            // Tuesday: everything eaten as planned, a green dot.
            $date === '2026-09-22' => [$meal['kcal'], $meal['protein_g'], $meal['fat_g'], $meal['carbs_g'], null],
            // Monday: breakfast well under target, lunch well over, dinner open.
            $date === '2026-09-21' && $index === 1 => [400, 30, 10, 50, null],
            $date === '2026-09-21' && $index === 2 => [1100, 60, 30, 150, null],
            // Wednesday lunch: a little over, still on target.
            $date === '2026-09-23' && $index === 2 => [790, 55, 20, 100, '12:40'],
            default => null,
        };
        if ($values === null) {
            return null;
        }

        return (new MealCheckIn)->forceFill([
            'id' => "ci-{$date}-{$index}", 'user_id' => 1, 'meal_id' => self::mealId($date, $index), 'time' => $values[4],
            'kcal' => $values[0], 'protein_g' => $values[1], 'fat_g' => $values[2], 'carbs_g' => $values[3], 'is_manual' => false,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    public static function mesocycle(array $attributes = []): Mesocycle
    {
        return (new Mesocycle)->forceFill(array_replace([
            'id' => self::MESO, 'user_id' => 1, 'phase_id' => null, 'name' => 'Meso A', 'weeks' => 4,
            'start_date' => self::PLAN_START, 'ended_on' => null, 'is_draft' => false, 'is_resensitization' => false,
            'route' => null, 'active_rest' => false, 'muscle_priorities' => [], 'earlier_weeks' => [], 'later_weeks' => [],
            'days' => [
                ['id' => 'upper-a', 'label' => 'Upper A', 'weekday' => null, 'slots' => [
                    self::slot('ua-1', 12, 'chest', 4), self::slot('ua-2', 25, 'back', 3), self::slot('ua-3', null, 'biceps', 3),
                ]],
                ['id' => 'lower-a', 'label' => 'Lower A', 'weekday' => null, 'slots' => [
                    self::slot('la-1', 30, 'quads', 4), self::slot('la-2', null, 'hamstrings', 3),
                ]],
                ['id' => 'upper-b', 'label' => 'Upper B', 'weekday' => null, 'slots' => [
                    self::slot('ub-1', 12, 'chest', 3), self::slot('ub-2', 25, 'back', 3),
                ]],
            ],
        ], $attributes));
    }

    /** @return array<string, mixed> */
    private static function slot(string $id, ?int $exercise, string $muscle, int $sets): array
    {
        return ['id' => $id, 'exercise_id' => $exercise, 'muscle_group' => $muscle, 'sets' => $sets];
    }

    public static function planWeek(
        string $start, bool $deload = false, bool $away = false, ?string $awayTraining = null, bool $rough = false,
        ?string $awayCalories = null, bool $dietBreak = false, bool $keepPhaseEnd = false,
    ): PlanWeek {
        return (new PlanWeek)->forceFill([
            'id' => "pw-{$start}", 'user_id' => 1, 'start_date' => $start, 'is_deload' => $deload,
            'is_diet_break' => $dietBreak, 'diet_break_off' => false, 'is_away' => $away, 'away_training' => $awayTraining,
            'away_calories' => $awayCalories, 'is_rough' => $rough, 'keep_phase_end' => $keepPhaseEnd,
        ]);
    }

    /** The plan's one phase: Fat loss from the plan start to 2026-12-05 (the end is the first day after its last week). */
    public static function phase(string $type = 'fatLoss', string $start = self::PLAN_START, ?string $end = '2026-12-05'): Phase
    {
        return (new Phase)->forceFill([
            'id' => 'cccccccc-0000-4000-8000-000000000001', 'user_id' => 1, 'type' => $type, 'start_date' => $start, 'end_date' => $end,
            'target_rate_percent_per_week' => -0.5, 'event_date' => null,
        ]);
    }

    public static function session(string $id, int $week, int $day, ?string $start = null, ?string $end = null, bool $skipped = false, ?string $plannedStart = null): WorkoutSession
    {
        return (new WorkoutSession)->forceFill([
            'id' => $id, 'user_id' => 1, 'mesocycle_id' => self::MESO, 'week' => $week, 'day' => $day,
            'planned_start_at' => $plannedStart, 'actual_start_at' => $start, 'actual_end_at' => $end, 'is_skipped' => $skipped,
        ]);
    }

    /**
     * Upper A of week 2 (Monday 21 September) is done with 8 of its 10 sets logged. Upper A of weeks 1, 2 and 3 each
     * have an actual length, so week 4's estimate is the mean of the last 3.
     *
     * @return list<WorkoutSession>
     */
    public static function sessions(): array
    {
        return [
            self::session('w1d1', 1, 1, '2026-09-14 17:30:00', '2026-09-14 18:10:00'),
            self::session('w2d1', 2, 1, '2026-09-21 17:30:00', '2026-09-21 18:20:00'),
            self::session('w3d1', 3, 1, '2026-09-28 17:30:00', '2026-09-28 18:15:00'),
            self::session('w2d2', 2, 2),
        ];
    }

    /** @return list<WorkoutSet> */
    private static function sets(): array
    {
        return array_map(fn (int $i) => (new WorkoutSet)->forceFill([
            'id' => "s{$i}", 'user_id' => 1, 'workout_session_id' => 'w2d1', 'exercise_id' => 12, 'position' => $i,
            'type' => 'regular', 'is_logged' => $i <= 8, 'is_skipped' => false,
        ]), range(1, 10));
    }

    /** @return list<Exercise> */
    private static function exercises(): array
    {
        return array_map(fn (array $row) => (new Exercise)->forceFill(['id' => $row[0], 'name' => $row[1], 'muscle_group' => $row[2]]), [
            [12, 'Bench Press', 'chest'], [25, 'Barbell Row', 'back'], [30, 'Back Squat', 'quads'],
        ]);
    }

    public static function offPlan(string $id, string $date = self::TODAY, ?string $name = null, ?string $time = null, float $kcal = 300, float $protein = 20): OffPlanEntry
    {
        return (new OffPlanEntry)->forceFill([
            'id' => $id, 'user_id' => 1, 'date' => $date, 'name' => $name, 'time' => $time,
            'kcal' => $kcal, 'protein_g' => $protein, 'fat_g' => 10, 'carbs_g' => 30,
        ]);
    }

    public static function activity(string $id, string $date = self::TODAY, string $start = '18:45', int $minutes = 45, string $name = 'Tennis', string $intensity = 'moderate'): OtherActivity
    {
        return (new OtherActivity)->forceFill([
            'id' => $id, 'user_id' => 1, 'date' => $date, 'start_time' => $start, 'minutes' => $minutes, 'name' => $name, 'intensity' => $intensity,
        ]);
    }
}
