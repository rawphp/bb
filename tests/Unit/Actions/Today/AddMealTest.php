<?php

use App\Actions\Today\AddMeal;
use App\Actions\Today\RetargetableDay;
use App\Capabilities\Today\Data\MealAddInput;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SheetSeed;
use Tests\Support\TrainSeed;

// What meal_add saves (SCH-031). The split is RetargetTest's (addMeal). Wednesday 23 September 2026 holds no session;
// its three meals share 2,600 kcal.
uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-09-22 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

const ADDED_MEAL = '01JBX0M1N2P3Q4R5S6T7V8W9XA';

function addMeal(User $user, string $date = '2026-09-23', string $time = '15:00'): array
{
    return app(AddMeal::class)($user, new MealAddInput(ADDED_MEAL, $date, $time));
}

it('SCH-031 adds an extra meal at the chosen time under the client\'s id, and shares the day\'s total over the open meals', function () {
    $user = TrainSeed::user();
    TrainSeed::mesocycle($user);
    $day = SheetSeed::day($user);

    $added = addMeal($user);

    $meals = collect($day->fresh()->meals);
    $extra = $meals->firstWhere('id', strtolower(ADDED_MEAL));
    expect($added)->toBe(['toast' => 'Meal added at 3:00 PM', 'dates' => ['2026-09-23'], 'week' => [
        '2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25', '2026-09-26', '2026-09-27',
    ]])
        ->and($meals)->toHaveCount(4)
        ->and($extra['name'])->toBe('Extra meal')
        ->and($extra['time'])->toBe('15:00')
        ->and($extra['kcal'])->toBeGreaterThan(0)
        // Every meal keeps its time, and the day's total holds.
        ->and($meals->pluck('time')->all())->toBe(['07:30', '12:30', '19:00', '15:00'])
        ->and($day->fresh()->kcal)->toBe(2600.0)
        ->and($meals->sum('kcal'))->toEqualWithDelta(2600.0, 0.5)
        ->and($meals->sum('protein_g'))->toEqualWithDelta(170.0, 0.5);
});

it('SCH-031 a checked-in meal keeps its target, and the open meals share what it leaves', function () {
    $user = TrainSeed::user();
    $day = SheetSeed::day($user);
    $breakfast = SheetSeed::id(SheetSeed::BREAKFAST);
    SheetSeed::checkIn($breakfast);
    $before = collect($day->meals)->firstWhere('id', $breakfast);

    addMeal($user);

    $meals = collect($day->fresh()->meals);
    expect($meals->firstWhere('id', $breakfast))->toEqual($before)
        ->and($meals->where('id', '!=', $breakfast)->sum('kcal'))->toEqualWithDelta(2600.0 - $before['kcal'], 0.5);
});

it('SCH-031 refuses a past day and a day with no meals planned, and saves nothing', function (string $date, string $message) {
    $user = TrainSeed::user();
    $day = SheetSeed::day($user, '2026-09-21');
    $before = $day->meals;

    expect(fn () => addMeal($user, $date))->toThrow(DomainException::class, $message)
        ->and($day->fresh()->meals)->toEqual($before);
})->with([
    'a past day' => ['2026-09-21', RetargetableDay::PAST],
    'a day with no targets' => ['2026-09-25', RetargetableDay::NO_MEALS],
]);
