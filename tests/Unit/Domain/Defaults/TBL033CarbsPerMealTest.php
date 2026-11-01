<?php

use App\Domain\Defaults\TBL033CarbsPerMeal;
use App\Domain\Defaults\TBL033CarbsPerMeal\Row;

it('TBL-033 carbs per meal on training days', function (int $meals, float $pre, float $post, ?float $bedtime, ?float $rest, ?int $restMeals) {
    expect(TBL033CarbsPerMeal::row($meals))->toEqual(new Row($meals, $pre, $post, $bedtime, $rest, $restMeals));
})->with([
    '2 meals' => [2, 40.0, 60.0, null, null, null],
    '3 meals' => [3, 30.0, 45.0, 25.0, null, null],
    '4 meals' => [4, 25.0, 35.0, 25.0, 15.0, 1],
    '5 meals' => [5, 20.0, 30.0, 20.0, 30.0, 2],
    '6 meals' => [6, 18.0, 25.0, 18.0, 39.0, 3],
]);

it('TBL-033 lists the rows in meal order', function () {
    expect(array_map(fn (Row $row) => $row->meals, TBL033CarbsPerMeal::rows()))->toBe([2, 3, 4, 5, 6]);
});

it('TBL-033 looks up a row and the intra-workout shake', function () {
    expect(TBL033CarbsPerMeal::row(5)?->postWorkoutPercent)->toBe(30.0)
        ->and(TBL033CarbsPerMeal::row(7))->toBeNull()
        ->and(TBL033CarbsPerMeal::INTRA_WORKOUT_SHAKE_TAKES_POINTS)->toBe(10.0);
});
