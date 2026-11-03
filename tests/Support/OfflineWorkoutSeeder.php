<?php

namespace Tests\Support;

use App\Domain\Model\MuscleGroup;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * The account tests/e2e/offline-workout.spec.ts signs in to (OFF-050), on the day in BB_E2E_DAY (Y-m-d), the day the
 * test walks the clock to. That day it trains at 17:30 for 60 minutes: Bench Press and Back Squat, 2 sets each, not
 * started. It eats Breakfast 07:30, Lunch 12:30 and Dinner 19:00 on a 2,600 kcal day in a muscle-gain phase, so a
 * session that ends far from its planned hour re-targets Dinner (SCH-054). The plan and a 4-week mesocycle start on the
 * Saturday on or before it. It writes the account and the workout's path to
 * storage/framework/testing/e2e-offline.json.
 *
 * BB_E2E_DAY=2026-11-04 php artisan db:seed --class='Tests\Support\OfflineWorkoutSeeder' (local and testing only).
 */
final class OfflineWorkoutSeeder extends Seeder
{
    public const EMAIL = 'offline@example.com';

    public function run(): void
    {
        // The password is public (UserFactory), as AccountSeeder's is.
        if (! app()->environment('local', 'testing')) {
            return;
        }

        $day = CarbonImmutable::parse((string) getenv('BB_E2E_DAY'));
        // Week 1 of both, so Today does not ask about missed weeks (SCH-057).
        $start = $day->isSaturday() ? $day : $day->previous(CarbonImmutable::SATURDAY);

        // Weekdays are stored ISO, 1 = Monday to 7 = Sunday (PlanWeekDays).
        $user = User::factory()->create(['name' => 'Offline User', 'email' => self::EMAIL]);
        Plan::factory()->for($user)->create(['start_date' => $start->toDateString(), 'review_weekday' => 6]);
        Profile::factory()->for($user)->create([
            'name' => 'Alex',
            'experience' => 'intermediate',
            'meal_count' => 3,
            'eating_window' => ['start' => '07:00', 'end' => '21:00'],
            'training_days' => [['weekday' => $day->dayOfWeekIso, 'start' => '17:30', 'minutes' => 60]],
        ]);
        TrainSeed::phase($user, 'muscleGain', $start->toDateString());
        $mesocycle = TrainSeed::mesocycle($user, [
            'name' => 'Offline block',
            'weeks' => 4,
            'start_date' => $start->toDateString(),
            'days' => [[
                'label' => 'Full Body',
                'weekday' => $day->dayOfWeekIso,
                'slots' => [
                    ['exercise_id' => TrainSeed::exercise('Bench Press', MuscleGroup::Chest)->id, 'muscle_group' => 'chest', 'sets' => 2],
                    ['exercise_id' => TrainSeed::exercise('Back Squat', MuscleGroup::Quads)->id, 'muscle_group' => 'quads', 'sets' => 2],
                ],
            ]],
        ]);
        SheetSeed::day($user, $day->toDateString());

        $week = intdiv((int) $start->diffInDays($day), 7) + 1;
        file_put_contents(storage_path('framework/testing/e2e-offline.json'), json_encode([
            'email' => self::EMAIL,
            'password' => 'password',
            'workout' => "/workouts/{$mesocycle->id}/{$week}/1",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
}
