<?php

use App\Domain\Model\Meal;
use App\Domain\Model\MealTarget;
use App\Domain\Placement\ClockTime;
use App\Support\Plan\DayTargetRow;

// NUT-030: one mapping from a meal and the day's totals to their `day_targets` columns, for every writer.

it('NUT-030 a meal is saved under its id with its time as HH:MM and its target', function () {
    $meal = new Meal('meal-1', 'Breakfast', new MealTarget(500.0, 40.0, 15.0, 50.0));

    expect(DayTargetRow::meal($meal, ClockTime::fromHhmm('07:30')))->toBe([
        'id' => 'meal-1',
        'name' => 'Breakfast',
        'time' => '07:30',
        'kcal' => 500.0,
        'protein_g' => 40.0,
        'fat_g' => 15.0,
        'carbs_g' => 50.0,
    ]);
});

it('NUT-030 the day totals are saved from its target', function () {
    expect(DayTargetRow::totals(new MealTarget(2400.0, 180.0, 70.0, 260.0)))->toBe([
        'kcal' => 2400.0, 'protein_g' => 180.0, 'fat_g' => 70.0, 'carbs_g' => 260.0,
    ]);
});

it('SAFE-003 a day with no target saves null totals', function () {
    expect(DayTargetRow::totals(null))->toBe(['kcal' => null, 'protein_g' => null, 'fat_g' => null, 'carbs_g' => null]);
});
