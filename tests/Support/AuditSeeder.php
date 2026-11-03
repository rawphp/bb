<?php

namespace Tests\Support;

use App\Domain\Model\MuscleGroup;
use App\Models\DayTarget;
use App\Models\Food;
use App\Models\MealCheckIn;
use App\Models\Mesocycle;
use App\Models\OffPlanEntry;
use App\Models\Plan;
use App\Models\PlannedFood;
use App\Models\Profile;
use App\Models\Step;
use App\Models\User;
use App\Models\WeighIn;
use App\Models\WeighInScheduleEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * The account tests/e2e signs in to (PWA-004, PWA-051), on dates around the server's today: a plan and a 4-week
 * mesocycle from a Saturday at least a week back, training Monday, Wednesday and Friday, 3 meals a day for two weeks
 * either side of today, and today with a planned breakfast, lunch eaten, a weigh-in, cake and steps. Today's
 * week's first workout is under way with its sets to do. It writes the paths of that workout and of today's
 * breakfast to storage/framework/testing/e2e.json for the Playwright tests.
 *
 * php artisan db:seed --class='Tests\Support\AuditSeeder' (local and testing only, after the area seeders).
 */
final class AuditSeeder extends Seeder
{
    public const EMAIL = 'audit@example.com';

    public function run(): void
    {
        // The password is public (UserFactory), as AccountSeeder's is.
        if (! app()->environment('local', 'testing')) {
            return;
        }

        $today = CarbonImmutable::today();
        $start = $today->subDays(7)->previous(CarbonImmutable::SATURDAY);

        $user = User::factory()->create(['name' => 'Audit User', 'email' => self::EMAIL]);
        Plan::factory()->for($user)->create(['start_date' => $start->toDateString(), 'review_weekday' => 6]);
        Profile::factory()->for($user)->create([
            'name' => 'Sam',
            'meal_count' => 3,
            'eating_window' => ['start' => '08:00', 'end' => '20:00'],
            'training_days' => [
                ['weekday' => 1, 'start' => '17:30', 'minutes' => 60],
                ['weekday' => 3, 'start' => '17:30', 'minutes' => 60],
                ['weekday' => 5, 'start' => '17:30', 'minutes' => 60],
            ],
            'step_plan' => [['weekday' => $today->dayOfWeek, 'min' => 6000, 'max' => 8000]],
        ]);
        WeighInScheduleEntry::factory()->for($user)->create(['weekdays' => [3, 6], 'time' => '07:00']);

        $mesocycle = TrainSeed::mesocycle($user, [
            'name' => 'Hypertrophy block',
            'weeks' => 4,
            'start_date' => $start->toDateString(),
            'days' => [
                $this->day('Upper A', 1, [[TrainSeed::exercise('Bench Press', MuscleGroup::Chest)->id, 'chest', 4]]),
                $this->day('Lower A', 3, [[TrainSeed::exercise('Back Squat', MuscleGroup::Quads)->id, 'quads', 4], [null, 'calves', 3]]),
                $this->day('Upper B', 5, [[TrainSeed::exercise('Bench Press', MuscleGroup::Chest)->id, 'chest', 3]]),
            ],
        ]);

        for ($day = $today->subDays(14); $day <= $today->addDays(14); $day = $day->addDay()) {
            $this->target($user, $day);
        }
        $this->today($user, $today);

        $week = intdiv((int) $start->diffInDays($today), 7) + 1;
        $workout = WorkoutSeed::started($mesocycle, $week, 1, $today->setTime(6, 0)->toDateTimeString(), reps: null);
        $breakfast = DayTarget::query()->where('user_id', $user->id)->whereDate('date', $today)->sole()->meals[0]['id'];

        $this->manifest([
            'email' => self::EMAIL,
            'password' => 'password',
            'today' => '/today',
            'workout' => "/workouts/{$mesocycle->id}/{$workout->week}/{$workout->day}",
            'meal' => "/meals/{$breakfast}",
        ]);
    }

    /**
     * @param  list<array{0: ?int, 1: string, 2: int}>  $slots  exercise id, muscle group, sets
     * @return array<string, mixed>
     */
    private function day(string $label, int $weekday, array $slots): array
    {
        return [
            'label' => $label,
            'weekday' => $weekday,
            'slots' => array_map(fn (array $slot) => ['exercise_id' => $slot[0], 'muscle_group' => $slot[1], 'sets' => $slot[2]], $slots),
        ];
    }

    private function target(User $user, CarbonImmutable $day): void
    {
        DayTarget::factory()->for($user)->create([
            'date' => $day->toDateString(), 'day_type' => 'hard', 'kcal' => 2600, 'protein_g' => 170, 'fat_g' => 70, 'carbs_g' => 320,
            'meals' => [
                ['name' => 'Breakfast', 'time' => '08:00', 'kcal' => 780, 'protein_g' => 51, 'fat_g' => 21, 'carbs_g' => 96],
                ['name' => 'Lunch', 'time' => '12:30', 'kcal' => 780, 'protein_g' => 51, 'fat_g' => 21, 'carbs_g' => 96],
                ['name' => 'Dinner', 'time' => '19:30', 'kcal' => 1040, 'protein_g' => 68, 'fat_g' => 28, 'carbs_g' => 128],
            ],
        ]);
    }

    /** Today as TodaySeed's Wednesday has it: planned breakfast, lunch eaten, a weigh-in, cake and steps. */
    private function today(User $user, CarbonImmutable $today): void
    {
        $day = DayTarget::query()->where('user_id', $user->id)->whereDate('date', $today)->sole();
        [$breakfast, $lunch] = array_column($day->meals, 'id');
        $oats = Food::query()->where('name', 'Oats')->first()
            ?? Food::factory()->createOne(['name' => 'Oats', 'kcal' => 380, 'protein' => 13, 'fat' => 7, 'carbs' => 66]);
        PlannedFood::factory()->create(['meal_id' => $breakfast, 'food_id' => $oats->getKey(), 'grams' => 80]);
        MealCheckIn::factory()->create(['meal_id' => $lunch, 'time' => '12:40', 'kcal' => 790, 'protein_g' => 55, 'fat_g' => 20, 'carbs_g' => 100, 'is_manual' => false]);
        WeighIn::factory()->for($user)->create(['weight_kg' => 82.4, 'weighed_at' => $today->setTime(7, 5)]);
        OffPlanEntry::factory()->for($user)->create(['date' => $today->toDateString(), 'name' => 'Office cake', 'time' => '15:00', 'kcal' => 320, 'protein_g' => 4, 'fat_g' => 16, 'carbs_g' => 40]);
        Step::factory()->for($user)->create(['date' => $today->toDateString(), 'count' => 5432]);
    }

    /** @param  array<string, string>  $paths */
    private function manifest(array $paths): void
    {
        file_put_contents(storage_path('framework/testing/e2e.json'), json_encode($paths, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
}
