<?php

use App\Capabilities\Support\ReadKey;
use Rawphp\Capabilities\Support\CapabilityData;

it('CAP-017 builds a read key from the capability name and its input as canonical JSON', function () {
    expect(ReadKey::for('today_show', ['date' => '2026-11-01']))->toBe('today_show{"date":"2026-11-01"}');
});

it('CAP-017 sorts input keys at every depth, so the same input always gives the same key', function () {
    $a = ReadKey::for('meal_show', ['meal' => 2, 'date' => '2026-11-01', 'filter' => ['z' => true, 'a' => [3, 1]]]);
    $b = ReadKey::for('meal_show', ['filter' => ['a' => [3, 1], 'z' => true], 'date' => '2026-11-01', 'meal' => 2]);

    expect($a)->toBe($b)
        ->toBe('meal_show{"date":"2026-11-01","filter":{"a":[3,1],"z":true},"meal":2}');
});

it('CAP-017 writes empty input as an empty JSON object', function () {
    expect(ReadKey::for('account_show'))->toBe('account_show{}');
});

it('CAP-017 encodes slashes, unicode and whole floats the way JSON.stringify does', function () {
    expect(ReadKey::for('food_search', ['q' => 'crème/brûlée', 'grams' => 100.0]))
        ->toBe('food_search{"grams":100,"q":"crème/brûlée"}');
});

it('CAP-017 accepts a capability input DTO', function () {
    $input = new class('2026-11-01') extends CapabilityData
    {
        public function __construct(public readonly string $date) {}
    };

    expect(ReadKey::for('today_show', $input))->toBe('today_show{"date":"2026-11-01"}');
});
