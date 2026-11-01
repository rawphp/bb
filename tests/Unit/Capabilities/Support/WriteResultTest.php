<?php

use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\WriteResult;

it('CAP-010 returns invalidates, toast and undo from a write', function () {
    $result = new WriteResult(
        invalidates: [ReadKey::for('today_show', ['date' => '2026-11-01'])],
        toast: 'Meals 3 and 4 re-targeted',
        undo: '01JBX8Z6Q7M0V2K3N4P5R6S7T8',
    );

    expect($result->toArray())->toBe([
        'invalidates' => ['today_show{"date":"2026-11-01"}'],
        'toast' => 'Meals 3 and 4 re-targeted',
        'undo' => '01JBX8Z6Q7M0V2K3N4P5R6S7T8',
    ]);
});

it('CAP-010 leaves toast and undo empty unless the write sets them', function () {
    expect((new WriteResult(invalidates: []))->toArray())
        ->toBe(['invalidates' => [], 'toast' => null, 'undo' => null]);
});

it('CAP-010 always puts invalidates, toast and undo in a write\'s output schema', function () {
    $schema = WriteResult::jsonSchema();

    expect($schema['required'])->toBe(['invalidates'])
        ->and($schema['properties']['invalidates']['type'])->toBe('array')
        ->and($schema['properties']['toast']['type'])->toBe(['string', 'null'])
        ->and($schema['properties']['undo']['type'])->toBe(['string', 'null']);
});
