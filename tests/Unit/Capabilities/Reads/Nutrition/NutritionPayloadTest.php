<?php

use App\Capabilities\Reads\Nutrition\NutritionPayload;
use App\Support\Today\TodaySnapshot;
use Tests\Unit\Capabilities\Reads\Today\TodayWorld as W;

// The Nutrition tab (rp-clone NutritionView): the day's meals, each opening the meal screen (NAV-002), from in-memory rows.

/** @return array<string, mixed> */
function nutritionShow(TodaySnapshot $snapshot): array
{
    return NutritionPayload::build($snapshot)->toArray();
}

it('NAV-001 MEAL-001 lists the day\'s meals with their time and planned against target calories', function () {
    $payload = nutritionShow(W::snapshot());

    expect($payload['date_text'])->toBe('Today')
        ->and($payload['empty_text'])->toBeNull()
        ->and($payload['meals'][0])->toBe([
            'meal_id' => '20260923-0000-4000-8000-000000000001',
            'name' => 'Breakfast',
            'time_text' => '8:00 AM',
            // 80 g oats and 30 g whey.
            'kcal_text' => '424 / 780 kcal',
            'check_in' => ['state' => 'notCheckedIn', 'text' => 'Not checked in', 'tone' => 'neutral'],
            'bars' => $payload['meals'][0]['bars'],
        ])
        ->and(array_column($payload['meals'], 'kcal_text'))->toBe(['424 / 780 kcal', '0 / 780 kcal', '0 / 1040 kcal']);
});

it('NAV-005 UI-003 each meal carries four macro bars: its planned food against its target', function () {
    $payload = nutritionShow(W::snapshot());

    // Breakfast: 80 g oats and 30 g whey (424 kcal, 34.4 P, 7.4 F, 55.2 C) against 780 / 51 / 21 / 96.
    expect($payload['meals'][0]['bars'])->toBe([
        ['label' => 'Cal', 'value_text' => '424/780', 'fraction' => 0.544, 'tone' => 'neutral'],
        ['label' => 'P', 'value_text' => '34/51', 'fraction' => 0.675, 'tone' => 'neutral'],
        ['label' => 'F', 'value_text' => '7/21', 'fraction' => 0.352, 'tone' => 'neutral'],
        ['label' => 'C', 'value_text' => '55/96', 'fraction' => 0.575, 'tone' => 'neutral'],
    ])
        // Dinner: nothing planned yet, so empty bars against its targets.
        ->and($payload['meals'][2]['bars'][0])->toBe(['label' => 'Cal', 'value_text' => '0/1040', 'fraction' => 0.0, 'tone' => 'neutral']);
});

it('NAV-005 UI-003 a checked-in meal\'s bars show what was eaten', function () {
    // Lunch was checked in at 790 kcal, 55 P, 20 F, 100 C against 780 / 51 / 21 / 96, with nothing planned.
    $bars = nutritionShow(W::snapshot())['meals'][1]['bars'];

    expect(array_column($bars, 'value_text'))->toBe(['790/780', '55/51', '20/21', '100/96'])
        ->and(array_column($bars, 'fraction'))->toBe([1.0, 1.0, 0.952, 1.0]);
});

it('NAV-005 UI-003 a meal planned past its target fills its bar and no further', function () {
    $snapshot = W::snapshot();
    $target = $snapshot->dayTarget($snapshot->date);
    $meals = $target->meals;
    $meals[0]['fat_g'] = 5;
    $meals[0]['kcal'] = 0;
    $target->forceFill(['meals' => $meals]);

    $bars = nutritionShow($snapshot)['meals'][0]['bars'];

    expect($bars[2])->toBe(['label' => 'F', 'value_text' => '7/5', 'fraction' => 1.0, 'tone' => 'neutral'])
        // A zero target cannot be filled.
        ->and($bars[0])->toBe(['label' => 'Cal', 'value_text' => '424/0', 'fraction' => 0.0, 'tone' => 'neutral']);
});

it('MEAL-047 each meal shows its check-in state', function () {
    $payload = nutritionShow(W::snapshot(date: '2026-09-21'));

    expect(array_column(array_column($payload['meals'], 'check_in'), 'state'))->toBe(['under', 'over', 'notCheckedIn'])
        ->and(array_column(array_column($payload['meals'], 'check_in'), 'tone'))->toBe(['warning', 'warning', 'neutral']);
});

it('NAV-001 lists the meals in time order, a meal without a time last and named by its place', function () {
    $snapshot = W::snapshot();
    $target = $snapshot->dayTarget($snapshot->date);
    $meals = $target->meals;
    $meals[0]['name'] = null;
    $meals[0]['time'] = null;
    $meals[2]['time'] = '11:00';
    $target->forceFill(['meals' => $meals]);

    $payload = nutritionShow($snapshot);

    expect(array_column($payload['meals'], 'name'))->toBe(['Dinner', 'Lunch', 'Meal 1'])
        ->and($payload['meals'][2]['time_text'])->toBeNull();
});

it('NAV-001 names another day by its date', function () {
    expect(nutritionShow(W::snapshot(date: '2026-09-24'))['date_text'])->toBe('Thursday, Sep 24');
});

it('NAV-001 says when no meals are planned on the day', function () {
    $snapshot = W::snapshot();
    $snapshot->dayTarget($snapshot->date)->forceFill(['meals' => []]);
    $payload = nutritionShow($snapshot);

    expect($payload['meals'])->toBe([])
        ->and($payload['empty_text'])->toBe('No meals planned today.')
        ->and(nutritionShow(W::snapshot(date: '2026-10-10'))['empty_text'])->toBe('No meals planned on Saturday, Oct 10.');
});

it('ONB-031 shows no meals before the plan starts, as Today does', function () {
    $payload = nutritionShow(W::snapshot(today: '2026-09-10', date: '2026-09-10'));

    expect($payload['meals'])->toBe([])
        ->and($payload['empty_text'])->toBe('No meals planned today.');
});
