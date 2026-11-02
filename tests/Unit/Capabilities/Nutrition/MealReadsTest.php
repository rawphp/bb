<?php

use App\Capabilities\Nutrition\MealReads;
use App\Capabilities\Support\ReadKey;
use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Carbon;

// The reads a change to one meal invalidates (CAP-010, CAP-011): the meal screen, the Nutrition tab, and Today and
// the week strip of its day. The plan starts Saturday 2026-09-12 with Saturday reviews; today is Wednesday 2026-09-23.

beforeEach(fn () => Carbon::setTestNow('2026-09-23 10:00:00'));

afterEach(fn () => Carbon::setTestNow());

function mealReadsMeal(string $date, ?Plan $plan): DayTargetMeal
{
    $user = (new User)->forceFill(['id' => 7])->setRelation('plan', $plan);

    return (new DayTargetMeal)->forceFill(['id' => 'meal-1', 'user_id' => 7, 'day_target_id' => 'dt', 'position' => 0])
        ->setRelation('dayTarget', (new DayTarget)->forceFill(['id' => 'dt', 'user_id' => 7, 'date' => $date]))
        ->setRelation('user', $user);
}

function mealReadsPlan(): Plan
{
    return (new Plan)->forceFill(['user_id' => 7, 'start_date' => '2026-09-12', 'review_weekday' => 6]);
}

/** @return list<string> */
function mealReadsWeek(string ...$dates): array
{
    return array_map(fn (string $date) => ReadKey::for('week_show', ['date' => $date]), $dates);
}

it('CAP-011 names every read a meal write changes', function () {
    expect(MealReads::AFFECTS)->toBe(['meal_show', 'nutrition_show', 'today_show', 'week_show']);
});

it('CAP-010 a meal dated today drops its screen, the day\'s Nutrition tab and Today, and the plan week\'s strip, each also without a date', function () {
    expect(MealReads::keys(mealReadsMeal('2026-09-23', mealReadsPlan())))->toBe([
        ReadKey::for('meal_show', ['meal_id' => 'meal-1']),
        ReadKey::for('nutrition_show', ['date' => '2026-09-23']),
        ReadKey::for('nutrition_show'),
        ReadKey::for('today_show', ['date' => '2026-09-23']),
        ReadKey::for('today_show'),
        ...mealReadsWeek('2026-09-19', '2026-09-20', '2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25'),
        ReadKey::for('week_show'),
    ]);
});

it('CAP-010 a meal dated tomorrow drops the keys with its date, and the strip of its plan week, today\'s', function () {
    expect(MealReads::keys(mealReadsMeal('2026-09-24', mealReadsPlan())))->toBe([
        ReadKey::for('meal_show', ['meal_id' => 'meal-1']),
        ReadKey::for('nutrition_show', ['date' => '2026-09-24']),
        ReadKey::for('today_show', ['date' => '2026-09-24']),
        ...mealReadsWeek('2026-09-19', '2026-09-20', '2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25'),
        ReadKey::for('week_show'),
    ]);
});

it('CAP-010 a meal in a later plan week names that week\'s strip and no dateless key', function () {
    expect(MealReads::keys(mealReadsMeal('2026-09-26', mealReadsPlan())))->toBe([
        ReadKey::for('meal_show', ['meal_id' => 'meal-1']),
        ReadKey::for('nutrition_show', ['date' => '2026-09-26']),
        ReadKey::for('today_show', ['date' => '2026-09-26']),
        ...mealReadsWeek('2026-09-26', '2026-09-27', '2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02'),
    ]);
});

it('CAP-010 without a plan the strip is named for the meal\'s day alone', function () {
    expect(MealReads::keys(mealReadsMeal('2026-09-24', null)))->toBe([
        ReadKey::for('meal_show', ['meal_id' => 'meal-1']),
        ReadKey::for('nutrition_show', ['date' => '2026-09-24']),
        ReadKey::for('today_show', ['date' => '2026-09-24']),
        ...mealReadsWeek('2026-09-24'),
    ]);
});
