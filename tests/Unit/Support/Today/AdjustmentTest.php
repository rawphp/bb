<?php

use App\Domain\Model\DayType;
use App\Domain\Model\MealTarget;
use App\Domain\Placement\ClockTime;
use App\Support\Today\Adjustment;
use App\Support\Today\AdjustOutcome;
use App\Support\Today\DayMeal;
use App\Support\Today\DayPlan;

// The Adjust rules (DAY-020, NUT-021 to NUT-024, SAFE-010), ported from rp-clone's TodayAdjustTests. No database.
// The plan week runs Monday 21 to Sunday 27 September; today is Wednesday 23rd. Each day is 2,400 kcal: 170 g
// protein, 70 g fat, 272.5 g carbs over Breakfast, Lunch and Dinner.

function adjustDay(string $date, array $eaten = []): DayPlan
{
    $meal = fn (string $id, string $name, int $hour, float $carbs) => new DayMeal(
        "{$date}-{$id}", $name, ClockTime::at($hour), new MealTarget(4 * 55 + 9 * 23 + 4 * $carbs, 55, 23, $carbs),
        eaten: in_array($id, $eaten, true) ? new MealTarget(500, 40, 20, 40) : null,
    );

    return new DayPlan(
        new DateTimeImmutable($date, new DateTimeZone('UTC')),
        DayType::Moderate,
        new MealTarget(2400, 170, 70, 272.5),
        [$meal('b', 'Breakfast', 7, 80), $meal('l', 'Lunch', 12, 90), $meal('d', 'Dinner', 19, 102.5)],
    );
}

/** @return list<DayPlan> */
function adjustWeek(array $eaten = []): array
{
    return array_map(fn (int $day) => adjustDay(sprintf('2026-09-%02d', $day), $day === 23 ? $eaten : []), range(21, 27));
}

function adjusting(string $mode, ?int $kcal, array $choice = [], array $eaten = []): AdjustOutcome
{
    $week = adjustWeek($eaten);

    return Adjustment::outcome(
        $week[2], $week, $mode, $kcal, $choice['from'] ?? null, $choice['to'] ?? null, $choice['other'] ?? null,
        $choice['direction'] ?? 'give', new DateTimeImmutable('2026-09-23', new DateTimeZone('UTC')), 80.0,
    );
}

it('DAY-020 a move between meals goes through carbs and the day holds', function () {
    $outcome = adjusting('meals', 100, ['from' => '2026-09-23-l', 'to' => '2026-09-23-d']);

    expect($outcome->isPlan())->toBeTrue()
        ->and($outcome->changed)->toHaveCount(1)
        ->and($outcome->changed[0]['target'])->toEqual(new MealTarget(2400, 170, 70, 272.5))
        ->and($outcome->changed[0]['meals']['2026-09-23-l']->carbsG)->toEqualWithDelta(65, 0.001)
        ->and($outcome->changed[0]['meals']['2026-09-23-d']->carbsG)->toEqualWithDelta(127.5, 0.001)
        ->and($outcome->changed[0]['meals']['2026-09-23-l']->proteinG)->toBe(55.0)
        ->and($outcome->changed[0]['meals']['2026-09-23-b']->carbsG)->toBe(80.0);
});

it('SAFE-010 a move that leaves a meal under 5 g of carbs is refused with a reason', function () {
    expect(adjusting('meals', 350, ['from' => '2026-09-23-l', 'to' => '2026-09-23-d'])->refusal)
        ->toBe('Lunch would keep less than 5 g of carbs.');
});

it('DAY-020 a move needs an amount and two different meals not checked in', function (?int $kcal, array $choice, array $eaten) {
    $outcome = adjusting('meals', $kcal, $choice, $eaten);

    expect($outcome->isPlan())->toBeFalse()
        ->and($outcome->refusal)->toBeNull()
        ->and($outcome->missing)->not->toBeNull();
})->with([
    'no amount' => [null, ['from' => '2026-09-23-l', 'to' => '2026-09-23-d'], []],
    'nothing' => [0, ['from' => '2026-09-23-l', 'to' => '2026-09-23-d'], []],
    'one meal' => [100, ['from' => '2026-09-23-l', 'to' => '2026-09-23-l'], []],
    'a checked-in meal' => [100, ['from' => '2026-09-23-b', 'to' => '2026-09-23-d'], ['b']],
]);

it('NUT-021 the other days are the rest of the plan week', function () {
    $week = adjustWeek();

    expect(array_map(fn (DayPlan $day) => $day->date->format('j'), Adjustment::otherDays($week[2], $week)))->toBe(['21', '22', '24', '25', '26', '27']);
});

it('NUT-021 DAY-020 a move to another day takes its carbs off this day\'s open meals and adds them to the other day\'s', function () {
    $outcome = adjusting('days', 400, ['other' => '2026-09-26'], eaten: ['b']);

    [$selected, $other] = $outcome->changed;
    expect($selected['date']->format('Y-m-d'))->toBe('2026-09-23')
        ->and($selected['target'])->toEqual(new MealTarget(2000, 170, 70, 172.5))
        ->and($other['target'])->toEqual(new MealTarget(2800, 170, 70, 372.5))
        ->and($selected['meals']['2026-09-23-b']->carbsG)->toBe(80.0)
        ->and($selected['meals']['2026-09-23-l']->carbsG + $selected['meals']['2026-09-23-d']->carbsG)->toEqualWithDelta(192.5 - 100, 0.001)
        ->and(array_sum(array_map(fn (MealTarget $meal) => $meal->carbsG, $other['meals'])))->toEqualWithDelta(272.5 + 100, 0.001);
});

it('NUT-021 calories can also be taken from another day', function () {
    $outcome = adjusting('days', 400, ['other' => '2026-09-26', 'direction' => 'take']);

    expect($outcome->changed[0]['target']->kcal)->toBe(2800.0)
        ->and($outcome->changed[1]['target']->kcal)->toBe(2000.0);
});

it('NUT-024 a day before today is finished and refused', function () {
    expect(adjusting('days', 100, ['other' => '2026-09-22'])->refusal)->toBe('Tuesday is finished, so its calories can\'t move.');
});

it('NUT-024 today is finished once every meal is checked in', function () {
    expect(adjusting('days', 100, ['other' => '2026-09-26'], eaten: ['b', 'l', 'd'])->refusal)->toBe('Wednesday is finished, so its calories can\'t move.');
});

it('NUT-022 a day cannot leave the band around the week\'s daily average', function () {
    expect(adjusting('days', 600, ['other' => '2026-09-26'])->refusal)
        ->toBe('That would take Wednesday outside 80% to 125% of the week\'s daily average.');
});

it('DAY-020 a move between days needs another day of the week', function () {
    expect(adjusting('days', 100, ['other' => '2026-09-23'])->missing)->toBe('Choose another day of this plan week.')
        ->and(adjusting('days', 100, ['other' => '2026-10-01'])->missing)->toBe('Choose another day of this plan week.');
});
