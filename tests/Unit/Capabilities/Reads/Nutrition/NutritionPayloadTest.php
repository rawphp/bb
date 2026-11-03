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
        ])
        ->and(array_column($payload['meals'], 'kcal_text'))->toBe(['424 / 780 kcal', '0 / 780 kcal', '0 / 1040 kcal']);
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
