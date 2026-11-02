<?php

use PHPUnit\Framework\ExpectationFailedException;
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

it('passes assertMatchesScreenFixture for a payload in the shape of its fixture', function () {
    assertMatchesScreenFixture('today_show', ScreenFixture::load('today_show.warning'));
});

it('fails assertMatchesScreenFixture on null where the output schema allows none', function () {
    $payload = ScreenFixture::load('today_show');
    $payload['totals']['calories'] = null;

    assertMatchesScreenFixture('today_show', $payload);
})->throws(ExpectationFailedException::class, 'today_show.output.json');

it('fails assertMatchesScreenFixture on a wrong object where the fixture holds null', function () {
    $payload = ScreenFixture::load('today_show');
    expect($payload['add_workout']['session'])->toBeNull();
    $payload['add_workout']['session'] = ['mesocycle_id' => '01JBX0A1B2C3D4E5F6G7H8J9K0', 'week' => 1, 'day' => 2, 'title' => 'Push'];

    assertMatchesScreenFixture('today_show', $payload);
})->throws(ExpectationFailedException::class, 'today_show.output.json');

it('fails assertMatchesScreenFixture when no output schema exists', function () {
    ScreenFixture::outputSchema('no_such_show');
})->throws(InvalidArgumentException::class, 'tests/fixtures/schemas/no_such_show.output.json');

it('fails assertMatchesScreenFixture when no fixture exists', function () {
    assertMatchesScreenFixture('no_such_show', []);
})->throws(InvalidArgumentException::class, 'tests/fixtures/screens/no_such_show.json');
