<?php

use App\Domain\MealSplit\CarbPlacement;
use App\Domain\MealSplit\DayEntry;
use App\Domain\MealSplit\MealSplit;
use App\Domain\MealSplit\MealSplitItem;
use App\Domain\Model\MealTarget;

// Ported from rp-clone ios/Tests/RPCoreTests/Domain/MealSplit/MealSplitTests.swift.

/** @return list<string> fresh ids, as Swift's `UUID()` */
function mealSplitIds(int $count): array
{
    static $next = 0;

    return array_map(fn () => 'id-'.++$next, range(1, $count));
}

function mealSplitDay(float $protein, float $fat, float $carbs, float $kcal = 3000): MealTarget
{
    return new MealTarget(kcal: $kcal, proteinG: $protein, fatG: $fat, carbsG: $carbs);
}

/**
 * @param  list<string>  $ids
 * @return list<DayEntry>
 */
function mealSplitMeals(array $ids): array
{
    return array_map(fn (string $id) => DayEntry::meal($id), $ids);
}

/**
 * Six meals with the workout between meal 2 and meal 3.
 *
 * @return array{0: list<string>, 1: list<DayEntry>}
 */
function mealSplitTrainingDay6(): array
{
    $m = mealSplitIds(6);

    return [$m, [
        DayEntry::meal($m[0]), DayEntry::meal($m[1]), DayEntry::workout(),
        DayEntry::meal($m[2]), DayEntry::meal($m[3]), DayEntry::meal($m[4]), DayEntry::meal($m[5]),
    ]];
}

/** @return array{0: list<string>, 1: list<DayEntry>} */
function mealSplitRestDay(int $count): array
{
    $m = mealSplitIds($count);

    return [$m, mealSplitMeals($m)];
}

/** @param  list<MealSplitItem>  $items */
function mealSplitSum(array $items, string $key): float
{
    return array_sum(mealSplitValues($items, $key));
}

/**
 * @param  list<MealSplitItem>  $items
 * @return list<float>
 */
function mealSplitValues(array $items, string $key): array
{
    return array_map(fn (MealSplitItem $item) => $item->target->{$key}, $items);
}

/**
 * @param  list<MealSplitItem>  $items
 * @return list<string>
 */
function mealSplitItemIds(array $items): array
{
    return array_map(fn (MealSplitItem $item) => $item->id, $items);
}

// NUT-030

it('NUT-030 meals sum exactly to the day', function () {
    $m = mealSplitIds(5);
    $entries = [
        DayEntry::meal($m[0]), DayEntry::meal($m[1]), DayEntry::workout(),
        DayEntry::meal($m[2]), DayEntry::meal($m[3]), DayEntry::meal($m[4]),
    ];
    $items = MealSplit::split(mealSplitDay(kcal: 2773, protein: 187, fat: 61, carbs: 331), $entries);

    expect(mealSplitItemIds($items))->toBe($m)
        ->and(mealSplitSum($items, 'kcal'))->toEqualWithDelta(2773, 1e-9)
        ->and(mealSplitSum($items, 'proteinG'))->toEqualWithDelta(187, 1e-9)
        ->and(mealSplitSum($items, 'fatG'))->toEqualWithDelta(61, 1e-9)
        ->and(mealSplitSum($items, 'carbsG'))->toEqualWithDelta(331, 1e-9);
});

it('NUT-030 no meals gives no items', function () {
    expect(MealSplit::split(mealSplitDay(protein: 100, fat: 50, carbs: 200), [DayEntry::workout()]))->toBe([])
        ->and(MealSplit::split(mealSplitDay(protein: 100, fat: 50, carbs: 200), []))->toBe([]);
});

it('NUT-030 workouts produce no item', function () {
    [$m, $entries] = mealSplitTrainingDay6();
    $items = MealSplit::split(mealSplitDay(protein: 180, fat: 80, carbs: 500), $entries);

    expect(mealSplitItemIds($items))->toBe($m);
});

// NUT-031

it('NUT-031 training-day carbs follow TBL-033', function () {
    [$m, $entries] = mealSplitTrainingDay6();
    $items = MealSplit::split(mealSplitDay(protein: 180, fat: 80, carbs: 500), $entries);

    // 6-meal row: pre 18, post 25, bedtime 18, rest 39 over three meals (13 each).
    expect(mealSplitItemIds($items))->toBe($m)
        ->and(mealSplitValues($items, 'carbsG'))->toEqual([65, 90, 125, 65, 65, 90]);
});

it('NUT-031 rest-day carbs are even', function () {
    [, $entries] = mealSplitRestDay(6);
    $items = MealSplit::split(mealSplitDay(protein: 180, fat: 80, carbs: 480), $entries);

    expect(mealSplitValues($items, 'carbsG'))->toEqual([80, 80, 80, 80, 80, 80]);
});

it('NUT-031 rest-day carbs stay within one rounding step of even', function () {
    [, $entries] = mealSplitRestDay(6);
    $items = MealSplit::split(mealSplitDay(protein: 180, fat: 80, carbs: 500), $entries);
    $carbs = mealSplitValues($items, 'carbsG');

    expect(max($carbs) - min($carbs))->toBeLessThanOrEqual(5)
        ->and(array_sum($carbs))->toEqualWithDelta(500, 1e-9);
});

it('NUT-031 a post-workout meal that is also bedtime takes both shares', function () {
    $m = mealSplitIds(5);
    // Workout after meal 4: meal 5 is both post-workout and bedtime.
    $entries = [
        DayEntry::meal($m[0]), DayEntry::meal($m[1]), DayEntry::meal($m[2]), DayEntry::meal($m[3]),
        DayEntry::workout(), DayEntry::meal($m[4]),
    ];
    $items = MealSplit::split(mealSplitDay(protein: 180, fat: 80, carbs: 500), $entries);

    // 5-meal row: pre 20, post 30, bedtime 20, rest 30 over the three unnamed meals (10 each).
    expect(mealSplitValues($items, 'carbsG'))->toEqual([50, 50, 50, 100, 250]);
});

it('NUT-031 the six-meal row is used for more than six meals', function () {
    $m = mealSplitIds(7);
    $entries = [DayEntry::meal($m[0]), DayEntry::workout(), ...mealSplitMeals(array_slice($m, 1))];
    $items = MealSplit::split(mealSplitDay(protein: 200, fat: 100, carbs: 500), $entries);
    $carbs = mealSplitValues($items, 'carbsG');

    expect(array_sum($carbs))->toEqualWithDelta(500, 1e-9)
        ->and(max($carbs))->toBe($carbs[1], 'the post-workout meal has the most carbs');
});

// NUT-032

it('NUT-032 pre- and post-workout fat is lowest and at least five', function () {
    [, $entries] = mealSplitTrainingDay6();
    $items = MealSplit::split(mealSplitDay(protein: 180, fat: 80, carbs: 500), $entries);
    $fat = mealSplitValues($items, 'fatG');

    // Pre (meal 2) and post (meal 3) weigh half the others.
    expect($fat)->toEqual([15, 10, 10, 15, 15, 15])
        ->and(min($fat))->toBeGreaterThanOrEqual(5);
});

it('NUT-032 rest-day fat is even', function () {
    [, $entries] = mealSplitRestDay(4);
    $items = MealSplit::split(mealSplitDay(protein: 180, fat: 80, carbs: 400), $entries);

    expect(mealSplitValues($items, 'fatG'))->toEqual([20, 20, 20, 20]);
});

// NUT-033

it('NUT-033 protein is even with the bedtime meal about fifteen percent more', function () {
    [, $entries] = mealSplitTrainingDay6();
    $items = MealSplit::split(mealSplitDay(protein: 246, fat: 80, carbs: 500), $entries);

    // Weights 1,1,1,1,1,1.15 give 40,40,40,40,40,46 exactly.
    expect(mealSplitValues($items, 'proteinG'))->toEqual([40, 40, 40, 40, 40, 46]);
});

// NUT-034

it('NUT-034 exact rounding on a rest day', function () {
    [, $entries] = mealSplitRestDay(3);
    $items = MealSplit::split(mealSplitDay(kcal: 1000, protein: 100, fat: 63, carbs: 100), $entries);

    expect(mealSplitValues($items, 'proteinG'))->toEqual([35, 30, 35])
        ->and(mealSplitValues($items, 'carbsG'))->toEqual([35, 35, 30])
        // Even 21 g each rounds to 20; the 3 g remainder lands on the largest meal (first on a tie).
        ->and(mealSplitValues($items, 'fatG'))->toEqual([23, 20, 20])
        ->and(mealSplitValues($items, 'kcal'))->toEqual([355, 325, 320]);
});

it('NUT-034 every value is a multiple of five except the largest meal', function () {
    $m = mealSplitIds(5);
    $entries = [
        DayEntry::meal($m[0]), DayEntry::meal($m[1]), DayEntry::workout(),
        DayEntry::meal($m[2]), DayEntry::meal($m[3]), DayEntry::meal($m[4]),
    ];
    $items = MealSplit::split(mealSplitDay(kcal: 2773, protein: 187, fat: 61, carbs: 331), $entries);

    foreach (['kcal', 'proteinG', 'fatG', 'carbsG'] as $key) {
        $v = mealSplitValues($items, $key);
        $off = array_values(array_filter($v, fn (float $x) => fmod($x, 5) != 0));
        expect(count($off))->toBeLessThanOrEqual(1, $key);
        if ($off !== []) {
            expect($off[0])->toBeGreaterThanOrEqual(max($v), "{$key}: the remainder sits on the largest meal");
        }
    }
});

// NUT-035

it('NUT-035 a hand-edited meal stays fixed and the others absorb', function () {
    $m = mealSplitIds(4);
    $fixed = new MealTarget(kcal: 700, proteinG: 50, fatG: 20, carbsG: 150);
    $entries = [
        DayEntry::meal($m[0]), DayEntry::meal($m[1], fixed: $fixed), DayEntry::meal($m[2]), DayEntry::meal($m[3]),
    ];
    $items = MealSplit::split(mealSplitDay(kcal: 3000, protein: 160, fat: 80, carbs: 400), $entries);

    expect($items[1]->target)->toEqual($fixed)
        ->and(mealSplitValues($items, 'proteinG'))->toEqual([35, 50, 35, 40])
        ->and(mealSplitValues($items, 'fatG'))->toEqual([20, 20, 20, 20])
        ->and(mealSplitValues($items, 'carbsG'))->toEqual([85, 150, 85, 80])
        ->and(mealSplitValues($items, 'kcal'))->toEqual([770, 700, 765, 765])
        ->and(mealSplitSum($items, 'kcal'))->toEqualWithDelta(3000, 1e-9)
        ->and(mealSplitSum($items, 'proteinG'))->toEqualWithDelta(160, 1e-9)
        ->and(mealSplitSum($items, 'fatG'))->toEqualWithDelta(80, 1e-9)
        ->and(mealSplitSum($items, 'carbsG'))->toEqualWithDelta(400, 1e-9);
});

// NUT-036

it('NUT-036 a very low fat day keeps five grams per meal', function () {
    [, $entries] = mealSplitTrainingDay6();
    $items = MealSplit::split(mealSplitDay(protein: 180, fat: 32, carbs: 500), $entries);
    $fat = mealSplitValues($items, 'fatG');

    expect(min($fat))->toBeGreaterThanOrEqual(5)
        ->and($fat)->toEqual([7, 5, 5, 5, 5, 5])
        ->and(array_sum($fat))->toEqualWithDelta(32, 1e-9);
});

it('NUT-036 a low carb day keeps five grams per meal', function () {
    [, $entries] = mealSplitTrainingDay6();
    $items = MealSplit::split(mealSplitDay(protein: 180, fat: 80, carbs: 35), $entries);
    $carbs = mealSplitValues($items, 'carbsG');

    expect(min($carbs))->toBeGreaterThanOrEqual(5)
        ->and($carbs)->toEqual([5, 5, 10, 5, 5, 5])
        ->and(array_sum($carbs))->toEqualWithDelta(35, 1e-9);
});

it('NUT-036 floors win when the day cannot cover them', function () {
    [, $entries] = mealSplitTrainingDay6();
    $items = MealSplit::split(mealSplitDay(protein: 180, fat: 20, carbs: 20), $entries);

    expect(mealSplitValues($items, 'fatG'))->toEqual([5, 5, 5, 5, 5, 5])
        ->and(mealSplitValues($items, 'carbsG'))->toEqual([5, 5, 5, 5, 5, 5]);
});

it('NUT-036 the floor holds after a hand edit absorbs most of the day', function () {
    $m = mealSplitIds(3);
    $fixed = new MealTarget(kcal: 900, proteinG: 60, fatG: 40, carbsG: 100);
    $entries = [DayEntry::meal($m[0], fixed: $fixed), DayEntry::meal($m[1]), DayEntry::meal($m[2])];
    $items = MealSplit::split(mealSplitDay(kcal: 1500, protein: 120, fat: 45, carbs: 120), $entries);

    expect($items[0]->target)->toEqual($fixed)
        ->and(mealSplitValues($items, 'fatG'))->toEqual([40, 5, 5])
        ->and($items[1]->target->carbsG)->toBeGreaterThanOrEqual(5)
        ->and($items[2]->target->carbsG)->toBeGreaterThanOrEqual(5);
});

// NUT-037

it('NUT-037 evening-weighted moves ten points from pre to bedtime', function () {
    [, $entries] = mealSplitTrainingDay6();
    $target = mealSplitDay(protein: 180, fat: 80, carbs: 500);

    $centred = MealSplit::split($target, $entries);
    expect(mealSplitValues($centred, 'carbsG'))->toEqual([65, 90, 125, 65, 65, 90]);

    $evening = MealSplit::split($target, $entries, CarbPlacement::EveningWeighted);
    // Pre 18 -> 8 points, bedtime 18 -> 28 points.
    expect(mealSplitValues($evening, 'carbsG'))->toEqual([65, 40, 125, 65, 65, 140]);
});

it('NUT-037 the default placement is training-centred', function () {
    [, $entries] = mealSplitTrainingDay6();
    $target = mealSplitDay(protein: 180, fat: 80, carbs: 500);

    expect(MealSplit::split($target, $entries))
        ->toEqual(MealSplit::split($target, $entries, CarbPlacement::TrainingCentred));
});

// TBL-033 shake

it('TBL-033 a shake takes ten points from post', function () {
    $m = mealSplitIds(6);
    [$shake] = mealSplitIds(1);
    $entries = [
        DayEntry::meal($m[0]), DayEntry::meal($m[1]), DayEntry::workout(), DayEntry::intraWorkoutShake($shake),
        DayEntry::meal($m[2]), DayEntry::meal($m[3]), DayEntry::meal($m[4]), DayEntry::meal($m[5]),
    ];
    $items = MealSplit::split(mealSplitDay(protein: 180, fat: 80, carbs: 500), $entries);

    expect(mealSplitItemIds($items))->toBe([$m[0], $m[1], $shake, $m[2], $m[3], $m[4], $m[5]])
        // Shake 10 points (50 g); post 25 -> 15 points (75 g).
        ->and(mealSplitValues($items, 'carbsG'))->toEqual([65, 90, 50, 75, 65, 65, 90])
        ->and($items[2]->target->fatG)->toEqual(0)
        ->and(mealSplitSum($items, 'carbsG'))->toEqualWithDelta(500, 1e-9)
        ->and(mealSplitSum($items, 'proteinG'))->toEqualWithDelta(180, 1e-9)
        ->and(mealSplitSum($items, 'fatG'))->toEqualWithDelta(80, 1e-9)
        ->and(mealSplitSum($items, 'kcal'))->toEqualWithDelta(3000, 1e-9);
});

it('TBL-033 a shake never takes fat when every meal is fixed', function () {
    $m = mealSplitIds(3);
    [$shake] = mealSplitIds(1);
    $fixed = new MealTarget(kcal: 600, proteinG: 40, fatG: 20, carbsG: 100);
    $entries = [
        DayEntry::meal($m[0], fixed: $fixed), DayEntry::workout(), DayEntry::intraWorkoutShake($shake),
        DayEntry::meal($m[1], fixed: $fixed), DayEntry::meal($m[2], fixed: $fixed),
    ];
    $items = MealSplit::split(mealSplitDay(protein: 150, fat: 80, carbs: 500), $entries);

    expect(mealSplitItemIds($items))->toBe([$m[0], $shake, $m[1], $m[2]])
        ->and($items[0]->target)->toEqual($fixed)
        ->and($items[2]->target)->toEqual($fixed)
        ->and($items[3]->target)->toEqual($fixed)
        ->and($items[1]->target->fatG)->toEqual(0)
        // Carbs and protein have weight, so the shake takes what the fixed meals leave.
        ->and($items[1]->target->carbsG)->toEqual(200)
        ->and($items[1]->target->proteinG)->toEqual(30);
});

it('TBL-033 two shakes take ten points each from post', function () {
    $m = mealSplitIds(6);
    $shakes = mealSplitIds(2);
    $entries = [
        DayEntry::meal($m[0]), DayEntry::meal($m[1]), DayEntry::workout(),
        DayEntry::intraWorkoutShake($shakes[0]), DayEntry::intraWorkoutShake($shakes[1]),
        DayEntry::meal($m[2]), DayEntry::meal($m[3]), DayEntry::meal($m[4]), DayEntry::meal($m[5]),
    ];
    $items = MealSplit::split(mealSplitDay(protein: 180, fat: 80, carbs: 500), $entries);

    // Shakes 10 points (50 g) each; post 25 -> 5 points (25 g).
    expect(mealSplitValues($items, 'carbsG'))->toEqual([65, 90, 50, 50, 25, 65, 65, 90])
        ->and(mealSplitSum($items, 'carbsG'))->toEqualWithDelta(500, 1e-9);
});

it('TBL-033 a shake on a rest day takes no protein', function () {
    $m = mealSplitIds(3);
    [$shake] = mealSplitIds(1);
    $entries = [...mealSplitMeals($m), DayEntry::intraWorkoutShake($shake)];
    $items = MealSplit::split(mealSplitDay(protein: 150, fat: 60, carbs: 300), $entries);

    expect($items[3]->id)->toBe($shake)
        ->and($items[3]->target->proteinG)->toEqual(0)
        ->and($items[3]->target->carbsG)->toEqual(0)
        ->and($items[3]->target->fatG)->toEqual(0)
        ->and(mealSplitSum($items, 'proteinG'))->toEqualWithDelta(150, 1e-9);
});

// Workout position

it('NUT-031 a workout before the first meal gives it the pre and post shares', function () {
    $m = mealSplitIds(6);
    $entries = [DayEntry::workout(), ...mealSplitMeals($m)];
    $items = MealSplit::split(mealSplitDay(protein: 180, fat: 80, carbs: 500), $entries);

    // 6-meal row: no meal before the workout, so meal 0 is post and takes pre 18 + post 25 = 43 points.
    expect($items[0]->target->carbsG)->toEqual(215)
        ->and(mealSplitSum($items, 'carbsG'))->toEqualWithDelta(500, 1e-9);
});

it('NUT-031 a workout after the last meal gives it the pre and post shares', function () {
    $m = mealSplitIds(4);
    $entries = [...mealSplitMeals($m), DayEntry::workout()];
    $items = MealSplit::split(mealSplitDay(protein: 160, fat: 80, carbs: 400), $entries);

    // 4-meal row: pre 25 + post 35 + bedtime 25 = 85 points on the last meal; rest 15 over three meals.
    expect(mealSplitValues($items, 'carbsG'))->toEqual([20, 20, 20, 340]);
});

// Floor clamp

it('NUT-036 the floor clamps a skewed split', function () {
    $m = mealSplitIds(5);
    $entries = [
        DayEntry::meal($m[0]), DayEntry::meal($m[1]), DayEntry::meal($m[2]), DayEntry::meal($m[3]),
        DayEntry::workout(), DayEntry::meal($m[4]),
    ];
    $items = MealSplit::split(mealSplitDay(protein: 150, fat: 60, carbs: 25), $entries);

    // Weights 10, 10, 10, 20, 50 over 25 g: unclamped the small meals fall under 5 g.
    expect(mealSplitValues($items, 'carbsG'))->toEqual([5, 5, 5, 5, 5]);
});

// Direction check (spec example)

it('NUT-031 NUT-032 direction check from the spec example', function () {
    $m = mealSplitIds(6);
    $entries = [DayEntry::meal($m[0]), DayEntry::workout(), ...mealSplitMeals(array_slice($m, 1))];
    $items = MealSplit::split(mealSplitDay(kcal: 2770, protein: 220, fat: 60, carbs: 337.5), $entries);

    $carbs = mealSplitValues($items, 'carbsG');
    $fat = mealSplitValues($items, 'fatG');
    $post = 1;
    $others = array_values(array_filter($fat, fn (int $i) => $i !== 0 && $i !== $post, ARRAY_FILTER_USE_KEY));

    expect(max($carbs))->toBe($carbs[$post])
        ->and(count(array_filter($carbs, fn (float $c) => $c === $carbs[$post])))
        ->toBe(1, 'post-workout carbs beat every other meal')
        ->and($fat[0])->toBeLessThan(min($others))
        ->and($fat[$post])->toBeLessThan(min($others))
        ->and(array_sum($carbs))->toEqualWithDelta(337.5, 1e-9)
        ->and(mealSplitSum($items, 'kcal'))->toEqualWithDelta(2770, 1e-9);
});

// Review round 1: cases the Swift suite leaves open.

it('NUT-031 with two workouts, pre is the last meal before the first and post the first meal after the last', function () {
    $m = mealSplitIds(5);
    $entries = [
        DayEntry::meal($m[0]), DayEntry::workout(), DayEntry::meal($m[1]), DayEntry::meal($m[2]),
        DayEntry::workout(), DayEntry::meal($m[3]), DayEntry::meal($m[4]),
    ];
    $items = MealSplit::split(mealSplitDay(protein: 180, fat: 80, carbs: 500), $entries);

    // 5-meal row: pre 20 (meal 0), post 30 (meal 3), bedtime 20 (meal 4), rest 30 over meals 1 and 2.
    expect(mealSplitValues($items, 'carbsG'))->toEqual([100, 75, 75, 150, 100]);
});

it('NUT-031 meals only between two workouts put the pre and post shares into the rest', function () {
    $m = mealSplitIds(3);
    $entries = [DayEntry::workout(), ...mealSplitMeals($m), DayEntry::workout()];
    $items = MealSplit::split(mealSplitDay(protein: 180, fat: 80, carbs: 400), $entries);

    // 3-meal row: no pre or post meal, so pre 30 + post 45 = 75 rest points over meals 0 and 1; bedtime keeps 25.
    expect(mealSplitValues($items, 'carbsG'))->toEqual([150, 150, 100]);
});

it('NUT-035 free meals take kcal from their grams when fixed meals use more than the day', function () {
    $m = mealSplitIds(3);
    $fixed = new MealTarget(kcal: 1600, proteinG: 60, fatG: 40, carbsG: 100);
    $entries = [DayEntry::meal($m[0], fixed: $fixed), DayEntry::meal($m[1]), DayEntry::meal($m[2])];
    $items = MealSplit::split(mealSplitDay(kcal: 1500, protein: 120, fat: 45, carbs: 120), $entries);

    // Free meals: P 30, F 5, C 10 each, so 4·30 + 9·5 + 4·10 = 205 kcal, not a share of the day's -100 left.
    expect($items[0]->target)->toEqual($fixed)
        ->and(mealSplitValues($items, 'kcal'))->toEqual([1600, 205, 205]);
});

it('NUT-037 evening-weighted with the workout before the first meal moves the shift from post to bedtime', function () {
    $m = mealSplitIds(6);
    $entries = [DayEntry::workout(), ...mealSplitMeals($m)];
    $items = MealSplit::split(mealSplitDay(protein: 180, fat: 80, carbs: 500), $entries, CarbPlacement::EveningWeighted);

    // 6-meal row: pre 18 -> 8 goes to post (25 + 8 = 33), bedtime 18 + 10 = 28, rest 39 over four meals.
    expect(mealSplitValues($items, 'carbsG'))->toEqual([165, 50, 50, 50, 45, 140]);
});

it('NUT-037 evening-weighted with the workout after the last meal changes nothing, as pre and bedtime are one meal', function () {
    $m = mealSplitIds(4);
    $entries = [...mealSplitMeals($m), DayEntry::workout()];
    $items = MealSplit::split(mealSplitDay(protein: 160, fat: 80, carbs: 400), $entries, CarbPlacement::EveningWeighted);

    expect(mealSplitValues($items, 'carbsG'))->toEqual([20, 20, 20, 340]);
});
