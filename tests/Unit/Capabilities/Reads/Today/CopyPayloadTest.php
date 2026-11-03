<?php

use App\Capabilities\Reads\Today\CopyPayload;
use App\Models\DayTarget;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Today\TodaySnapshot;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Unit\Capabilities\Reads\Today\TodayWorld as W;

// The Copy sheet (MEAL-060, SCH-034), ported from the sheet cases of rp-clone's CopyFoodsTests and CopyDayTests, from
// in-memory rows. Wednesday 2026-09-23 is a hard day: Breakfast 08:00 (780 kcal, 51 P, 21 F, 96 C) holds 80 g oats and
// 30 g whey, Lunch 12:30 is checked in, Dinner 19:30 is empty, and Lower A starts at 17:30. Thursday is a rest day:
// Breakfast 660 kcal, 51 P, 21 F, 69 C and Dinner 880 kcal, 68 P, 28 F, 92 C. The strip week runs Saturday to Friday.

/** @return array<string, mixed> Copy foods from `$mealId`, a meal of Wednesday, showing `$shown` */
function copyFoodsSheet(string $mealId, string $shown = W::TODAY, ?TodaySnapshot $snapshot = null): array
{
    $snapshot ??= W::snapshot(date: $shown);

    return CopyPayload::build($snapshot, TrainSnapshot::day(W::TODAY), $snapshot, TrainSnapshot::day($shown), $mealId)->toArray();
}

/** @return array<string, mixed> Copy day from `$source`, showing `$shown` */
function copyDaySheet(string $source = W::TODAY, ?string $shown = null, ?TodaySnapshot $snapshot = null): array
{
    $snapshot ??= W::snapshot(date: $shown ?? $source);

    return CopyPayload::build($snapshot, TrainSnapshot::day($source), $snapshot, TrainSnapshot::day($shown ?? $source), null)->toArray();
}

/**
 * @param  array<string, mixed>  $sheet
 * @return list<string> the strip's day numbers whose `$flag` is true
 */
function copyStripWhere(array $sheet, string $flag): array
{
    return array_column(array_filter($sheet['strip'], fn (array $day) => $day[$flag]), 'number');
}

// MARK: Copy foods

it('MEAL-060 the sheet shows the meal copied from', function () {
    $sheet = copyFoodsSheet(W::mealId('2026-09-23', 1));

    expect($sheet['mode'])->toBe('foods')
        ->and($sheet['title'])->toBe('Copy foods')
        ->and($sheet['source'])->toBe([
            'name' => 'Breakfast',
            'time_text' => '8:00 AM',
            'foods_text' => 'Oats 80 g · Whey 30 g',
            // 80 g oats and 30 g whey are not 780 kcal.
            'status' => ['text' => 'Targets not met', 'tone' => 'warning'],
        ]);
});

it('MEAL-060 the source shows Targets met when they are met', function () {
    $snapshot = W::snapshot(foods: [W::mealId('2026-09-23', 1) => [['fit', 100]]]);

    expect(copyFoodsSheet(W::mealId('2026-09-23', 1), snapshot: $snapshot)['source']['status'])->toBe(['text' => 'Targets met', 'tone' => 'good']);
});

it('MEAL-060 the sheet opens on the source\'s week and day', function () {
    $sheet = copyFoodsSheet(W::mealId('2026-09-23', 1));

    expect($sheet['shown_date'])->toBe('2026-09-23')
        ->and(array_column($sheet['strip'], 'date'))->toBe(['2026-09-19', '2026-09-20', '2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25'])
        ->and(array_column($sheet['strip'], 'letter'))->toBe(['S', 'S', 'M', 'T', 'W', 'T', 'F'])
        ->and(array_column($sheet['strip'], 'calories_text'))->toBe(['2200', '2200', '2400', '2200', '2600', '2200', '2400'])
        ->and(copyStripWhere($sheet, 'is_shown'))->toBe(['23'])
        ->and(copyStripWhere($sheet, 'is_today'))->toBe(['23'])
        ->and(copyStripWhere($sheet, 'has_training'))->toBe(['21', '23', '25'])
        // Every day of the week has meals to copy into.
        ->and(copyStripWhere($sheet, 'enabled'))->toBe(['19', '20', '21', '22', '23', '24', '25']);
});

it('MEAL-060 the week arrows keep the weekday and stay inside the plan', function () {
    $middle = copyFoodsSheet(W::mealId('2026-09-23', 1));
    $first = copyFoodsSheet(W::mealId('2026-09-23', 1), '2026-09-16');
    $last = copyFoodsSheet(W::mealId('2026-09-23', 1), '2026-10-07');

    expect([$middle['previous_week'], $middle['next_week']])->toBe(['2026-09-16', '2026-09-30'])
        // The plan starts on 2026-09-12: there is no week before that one.
        ->and($first['previous_week'])->toBeNull()
        // The last day with a target is 2026-10-09.
        ->and($last['next_week'])->toBeNull();
});

it('MEAL-060 the destinations are the shown day\'s meals in time order, with their targets and foods', function () {
    $sheet = copyFoodsSheet(W::mealId('2026-09-23', 1), '2026-09-24');

    expect($sheet['shown_date'])->toBe('2026-09-24')
        ->and(copyStripWhere($sheet, 'is_shown'))->toBe(['24'])
        ->and(array_column($sheet['meals'], 'name'))->toBe(['Breakfast', 'Lunch', 'Dinner'])
        ->and(array_column($sheet['meals'], 'time_text'))->toBe(['8:00 AM', '12:30 PM', '7:30 PM'])
        ->and(array_column($sheet['meals'], 'foods_text'))->toBe(['No foods yet', 'No foods yet', 'No foods yet'])
        // Thursday is a rest day: 2200 kcal, 170 g protein.
        ->and($sheet['meals'][0]['target_text'])->toBe('660 kcal · P 51 F 21 C 69')
        ->and($sheet['meals'][2]['target_text'])->toBe('880 kcal · P 68 F 28 C 92')
        ->and(array_column($sheet['meals'], 'enabled'))->toBe([true, true, true]);
});

it('MEAL-060 a meal with foods lists them', function () {
    $sheet = copyFoodsSheet(W::mealId('2026-09-23', 3), snapshot: W::snapshot(recipes: [W::mealId('2026-09-23', 2) => [[1.5, 0]]]));

    expect(array_column($sheet['meals'], 'foods_text'))->toBe(['Oats 80 g · Whey 30 g', 'Chili 1.5 servings', 'No foods yet']);
});

it('MEAL-060 the destinations show the workout between the meals, so the day\'s order is visible', function () {
    $wednesday = copyFoodsSheet(W::mealId('2026-09-23', 1));
    $thursday = copyFoodsSheet(W::mealId('2026-09-23', 1), '2026-09-24');
    $ids = fn (array $sheet) => array_map(fn (array $entry) => "{$entry['kind']}:{$entry['id']}", $sheet['timeline']);

    // Wednesday's session is at 17:30, after Lunch and before Dinner.
    expect($ids($wednesday))->toBe([
        'meal:'.W::mealId('2026-09-23', 1), 'meal:'.W::mealId('2026-09-23', 2), 'workout:workout', 'meal:'.W::mealId('2026-09-23', 3),
    ])
        ->and($wednesday['workouts'][0]['name'])->toBe('Lower A')
        ->and($wednesday['workouts'][0]['time_text'])->toMatch('/^5:30 PM · \d+ min$/')
        // A rest day has no block.
        ->and($ids($thursday))->toBe(array_map(fn (int $i) => 'meal:'.W::mealId('2026-09-24', $i), [1, 2, 3]))
        ->and($thursday['workouts'])->toBe([]);
});

it('MEAL-060 a checked-in meal and the source meal cannot take the copy, and say why', function () {
    $sheet = copyFoodsSheet(W::mealId('2026-09-23', 1));

    expect(array_column($sheet['meals'], 'enabled'))->toBe([false, false, true])
        ->and(array_column($sheet['meals'], 'note'))->toBe(['Copying from here', 'Checked in', null]);
});

it('MEAL-060 the options start at their defaults', function () {
    expect(copyFoodsSheet(W::mealId('2026-09-23', 1))['options'])->toBe([
        'update_target_macros' => true, 'update_meal_times' => false, 'copy_locked_state' => false,
    ]);
});

it('MEAL-060 a meal that is not on its day is not found', function () {
    $snapshot = W::snapshot();

    expect(fn () => CopyPayload::build($snapshot, TrainSnapshot::day('2026-09-24'), $snapshot, TrainSnapshot::day('2026-09-24'), W::mealId('2026-09-23', 1)))
        ->toThrow(ModelNotFoundException::class);
});

// MARK: Copy day

it('SCH-034 the Copy day sheet copies the day and lists no meals', function () {
    $sheet = copyDaySheet();

    expect($sheet['mode'])->toBe('day')
        ->and($sheet['title'])->toBe('Copy day')
        ->and($sheet['source'])->toBe(['name' => 'Wednesday, Sep 23', 'time_text' => null, 'foods_text' => '3 meals · 2 foods', 'status' => null])
        ->and($sheet['shown_date'])->toBe('2026-09-23')
        ->and([$sheet['timeline'], $sheet['meals'], $sheet['workouts']])->toBe([[], [], []])
        ->and($sheet['options']['update_target_macros'])->toBeTrue();
});

it('SCH-034 the source day and a day without a target cannot be chosen', function () {
    $snapshot = W::snapshot();
    $snapshot->dayTargets->forget($snapshot->dayTargets->search(fn (DayTarget $day) => TrainSnapshot::day($day->date)->format('Y-m-d') === '2026-09-25'));
    $sheet = copyDaySheet(snapshot: $snapshot);

    expect(copyStripWhere($sheet, 'enabled'))->toBe(['19', '20', '21', '22', '24'])
        ->and($sheet['strip'][6]['calories_text'])->toBe('–');
});

it('SCH-034 ONB-031 a day before the plan cannot be chosen', function () {
    $snapshot = W::snapshot(today: '2026-09-10', date: '2026-09-10');
    $sheet = CopyPayload::build($snapshot, TrainSnapshot::day('2026-09-12'), $snapshot, TrainSnapshot::day('2026-09-10'), null)->toArray();

    expect(copyStripWhere($sheet, 'is_before_plan'))->toBe(['5', '6', '7', '8', '9', '10', '11'])
        ->and(copyStripWhere($sheet, 'enabled'))->toBe([])
        ->and(array_unique(array_column($sheet['strip'], 'calories_text')))->toBe(['–']);
});

it('SCH-034 a day without a target is not found', function () {
    $snapshot = W::snapshot();

    expect(fn () => CopyPayload::build($snapshot, TrainSnapshot::day('2026-10-20'), $snapshot, TrainSnapshot::day('2026-10-20'), null))
        ->toThrow(ModelNotFoundException::class);
});
