<?php

use Tests\Support\ScreenFixture;

$fixture = [
    'title' => 'Today',
    'calories' => ['value' => 2400, 'unit' => 'kcal'],
    'meals' => [['name' => 'Meal 1', 'done' => false]],
    'note' => null,
];

it('matches a payload with the same keys and types and different values', function () use ($fixture) {
    $payload = [
        'title' => 'Tuesday',
        'calories' => ['value' => 2312.5, 'unit' => 'kcal'],
        'meals' => [['name' => 'A', 'done' => true], ['name' => 'B', 'done' => false]],
        'note' => null,
    ];

    expect(ScreenFixture::differences($fixture, $payload))->toBe([]);
});

it('matches an empty list against a list of items', function () use ($fixture) {
    expect(ScreenFixture::differences($fixture, [...$fixture, 'meals' => []]))->toBe([]);
});

it('matches null against any type, in either direction', function () use ($fixture) {
    $payload = [
        'title' => null,
        'calories' => ['value' => 2400, 'unit' => 'kcal'],
        'meals' => [['name' => 'A', 'done' => null]],
        'note' => 'Plan this meal before you eat it',
    ];

    expect(ScreenFixture::differences($fixture, $payload))->toBe([]);
});

it('names each key that is missing, extra or of another type', function () use ($fixture) {
    $payload = [
        'title' => 7,
        'calories' => ['value' => 2400],
        'meals' => [['name' => 'A', 'done' => false, 'extra' => 1]],
        'note' => null,
    ];

    expect(ScreenFixture::differences($fixture, $payload))->toBe([
        '$.title: expected string, got number',
        '$.calories.unit: missing from the payload',
        '$.meals[0].extra: not in the fixture',
    ]);
});

it('fails assertMatchesScreenFixture when no fixture exists', function () {
    assertMatchesScreenFixture('no_such_show', []);
})->throws(InvalidArgumentException::class, 'tests/fixtures/screens/no_such_show.json');
