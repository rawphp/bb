<?php

use App\Models\DayTarget;
use App\Models\MealCheckIn;
use App\Models\Phase;
use App\Models\User;
use App\Support\Plan\PhaseIntake;
use App\Support\Plan\PlanSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;

// PhaseIntake is a query, so it runs on the database. The phase runs 31 August to 19 October.
uses(RefreshDatabase::class);

/** @param  array<string, mixed>  $attributes */
function target(User $user, string $date, string $dayType, float $kcal, float $fatG, float $carbsG, int $meals = 1): DayTarget
{
    return DayTarget::factory()->for($user)->create([
        'date' => $date, 'day_type' => $dayType, 'kcal' => $kcal, 'fat_g' => $fatG, 'carbs_g' => $carbsG,
        'meals' => array_fill(0, $meals, ['name' => 'Meal', 'time' => '12:00', 'kcal' => 500, 'protein_g' => 35, 'fat_g' => 15, 'carbs_g' => 55]),
    ]);
}

it('PH-030 PH-044 PH-046 adherence over the phase, and the final week\'s kcal, fat and training-day carbs, from the user\'s own targets', function () {
    $user = User::factory()->create();
    $phase = Phase::factory()->for($user)->create(['start_date' => '2026-08-31', 'end_date' => '2026-10-19']);
    target($user, '2026-08-30', 'hard', 9000, 900, 900);                  // before the phase
    $early = target($user, '2026-09-10', 'hard', 2500, 80, 300, meals: 2); // in the phase, before its final week
    target($user, '2026-10-11', 'hard', 5000, 300, 900, meals: 0);      // the day before the final week
    target($user, '2026-10-12', 'deload', 2300, 80, 50, meals: 0);      // the final week's first day, a deload
    target($user, '2026-10-13', 'hard', 2100, 60, 200);
    $rest = target($user, '2026-10-14', 'rest', 1900, 70, 100);
    target($user, '2026-10-19', 'hard', 9000, 900, 900);                  // what comes next
    target(User::factory()->create(), '2026-10-13', 'hard', 4000, 200, 600); // another user's
    MealCheckIn::factory()->create(['meal_id' => $early->mealIndex()->first()->id]);
    MealCheckIn::factory()->create(['meal_id' => $rest->mealIndex()->first()->id]);

    $intake = PhaseIntake::load($user, $phase, PlanSnapshot::day('2026-10-19'));

    // 2 of the 4 planned meals checked in; the final week is 12 to 18 October; carbs from the hard day only, not the
    // rest day or the deload.
    expect($intake->adherence)->toBe(0.5)
        ->and($intake->finalWeekKcal)->toBe(2100.0)
        ->and($intake->finalWeekFatG)->toBe(70.0)
        ->and($intake->finalWeekTrainingCarbsG)->toBe(200.0);
});

it('PH-030 a phase with no targets has nothing to read', function () {
    $user = User::factory()->create();
    $phase = Phase::factory()->for($user)->create(['start_date' => '2026-08-31', 'end_date' => '2026-10-19']);

    expect(PhaseIntake::load($user, $phase, PlanSnapshot::day('2026-10-19')))->toEqual(new PhaseIntake);
});
