<?php

use App\Domain\Copy\MealRepeats;
use App\Domain\Copy\PastMeal;

// MEAL-061: the past meals an empty meal offers to have again. Each past meal names its day, its meal and what it held.

/** @param  list<string>  $keys */
function pastMeal(string $id, string $date, string $name, array $keys): PastMeal
{
    return new PastMeal(
        mealId: $id,
        day: new DateTimeImmutable($date, new DateTimeZone('UTC')),
        name: $name,
        entryKeys: $keys,
        entryNames: array_map(fn (string $key) => ucfirst(substr($key, strpos($key, ':') + 1)), $keys),
    );
}

/** @param  list<PastMeal>  $meals */
function repeatIds(string $name, array $meals): array
{
    return array_map(fn (PastMeal $meal) => $meal->mealId, (new MealRepeats)->pick($name, $meals));
}

it('MEAL-061 offers the same meal from earlier days, newest first', function () {
    $meals = [
        pastMeal('mon', '2026-11-02', 'Dinner', ['food:salmon']),
        pastMeal('tue', '2026-11-03', 'Dinner', ['food:chicken', 'food:rice']),
        pastMeal('sun', '2026-11-01', 'Dinner', ['recipe:chili']),
    ];

    expect(repeatIds('Dinner', $meals))->toBe(['tue', 'mon', 'sun']);
});

it('MEAL-061 offers only meals of the same name that held foods', function () {
    $meals = [
        pastMeal('lunch', '2026-11-03', 'Lunch', ['food:chicken']),
        pastMeal('empty', '2026-11-03', 'Dinner', []),
        pastMeal('dinner', '2026-11-02', 'Dinner', ['food:salmon']),
    ];

    expect(repeatIds('Dinner', $meals))->toBe(['dinner']);
});

it('MEAL-061 shows days that held the same foods once, whatever their order', function () {
    $meals = [
        pastMeal('tue', '2026-11-03', 'Dinner', ['food:chicken', 'food:rice']),
        pastMeal('mon', '2026-11-02', 'Dinner', ['food:rice', 'food:chicken']),
        pastMeal('sun', '2026-11-01', 'Dinner', ['food:chicken']),
    ];

    expect(repeatIds('Dinner', $meals))->toBe(['tue', 'sun']);
});

it('MEAL-061 offers at most three', function () {
    $meals = array_map(fn (int $day) => pastMeal("d{$day}", sprintf('2026-10-%02d', $day), 'Dinner', ["food:f{$day}"]), range(20, 25));

    expect(repeatIds('Dinner', $meals))->toBe(['d25', 'd24', 'd23']);
});

it('MEAL-061 offers nothing when no earlier meal of that name held foods', function () {
    expect(repeatIds('Dinner', []))->toBe([])
        ->and(repeatIds('Dinner', [pastMeal('b', '2026-11-03', 'Breakfast', ['food:oats'])]))->toBe([]);
});

it('MEAL-061 looks back 28 days and offers three', function () {
    expect(MealRepeats::LOOKBACK_DAYS)->toBe(28)
        ->and(MealRepeats::LIMIT)->toBe(3);
});
