<?php

use App\Models\MealCheckIn;
use Illuminate\Support\Carbon;
use Tests\Agent\Any;
use Tests\Agent\Coach;
use Tests\Support\SheetSeed;
use Tests\Support\TrainSeed;

// Eval requests about meals and food (AGT-050). SheetSeed's day is Wednesday 23 September 2026: 2,600 kcal over
// Breakfast 7:30, Lunch 12:30 and Dinner 19:00.
uses()->group('warp-isolated');

beforeEach(fn () => Carbon::setTestNow('2026-09-23 20:15:00'));
afterEach(fn () => Carbon::setTestNow());

it('AGT-050 "I ate a burrito instead of meal 3"', function () {
    $user = TrainSeed::user();
    SheetSeed::day($user);

    // Meal 3 is Dinner. The Coach checks it in at the burrito's amounts, which it estimates.
    $turn = Coach::says($user, 'I ate a burrito instead of meal 3', calls: [
        ['meal_check_in', ['meal_id' => SheetSeed::DINNER, 'kcal' => new Any(980), 'protein_g' => new Any(45), 'fat_g' => new Any(38), 'carbs_g' => new Any(110)]],
    ], reply: 'Checked in Dinner as a burrito, about 980 kcal.');

    // The burrito's amounts, as the Coach sent them, not Dinner's plan.
    $sent = $turn->call('meal_check_in')['arguments'];
    $checkIn = MealCheckIn::query()->where('meal_id', SheetSeed::DINNER)->sole();
    expect($checkIn->is_manual)->toBeTrue();
    foreach (['kcal', 'protein_g', 'fat_g', 'carbs_g'] as $amount) {
        expect((float) $checkIn->{$amount})->toBe((float) $sent[$amount]);
    }
});
