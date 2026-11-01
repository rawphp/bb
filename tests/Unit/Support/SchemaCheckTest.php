<?php

use Tests\Support\SchemaCheck;

$schema = [
    'type' => 'object',
    'additionalProperties' => false,
    'properties' => [
        'title' => ['type' => 'string'],
        'kcal' => ['type' => 'number'],
        'note' => ['type' => ['string', 'null']],
        'tone' => ['type' => 'string', 'enum' => ['good', 'warning']],
        'rows' => [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => ['count' => ['type' => 'integer']],
                'required' => ['count'],
            ],
        ],
    ],
    'required' => ['title', 'kcal', 'note', 'tone', 'rows'],
];

it('accepts a value that fits, with an integer where a number is asked', function () use ($schema) {
    $value = ['title' => 'Day', 'kcal' => 2400, 'note' => null, 'tone' => 'good', 'rows' => [['count' => 2]]];

    expect(SchemaCheck::violations($schema, $value))->toBe([]);
});

it('names each wrong type, unknown enum value, missing and extra key', function () use ($schema) {
    $value = ['title' => 7, 'kcal' => 'many', 'tone' => 'bad', 'rows' => [['count' => 1.5, 'x' => 1]], 'extra' => true];

    expect(SchemaCheck::violations($schema, $value))->toBe([
        '$.note: required',
        '$.title: expected string, got integer',
        '$.kcal: expected number, got string',
        '$.tone: "bad" is not one of ["good","warning"]',
        '$.rows[0].count: expected integer, got number',
        '$.rows[0].x: not in the schema',
        '$.extra: not in the schema',
    ]);
});

it('lets a nullable enum hold null, as the package leaves null out of the enum', function () {
    $schema = ['type' => ['string', 'null'], 'enum' => ['kg', 'lb']];

    expect(SchemaCheck::violations($schema, null))->toBe([])
        ->and(SchemaCheck::violations($schema, 'st'))->toBe(['$: "st" is not one of ["kg","lb"]']);
});

it('names a number outside its minimum or maximum', function () {
    $schema = ['type' => 'integer', 'minimum' => 1, 'maximum' => 14];

    expect(SchemaCheck::violations($schema, 1))->toBe([])
        ->and(SchemaCheck::violations($schema, 14))->toBe([])
        ->and(SchemaCheck::violations($schema, 0))->toBe(['$: 0 is below the minimum 1'])
        ->and(SchemaCheck::violations($schema, 15))->toBe(['$: 15 is above the maximum 14']);
});

it('names a string shorter or longer than its length bounds', function () {
    $schema = ['type' => 'string', 'minLength' => 2, 'maxLength' => 3];

    expect(SchemaCheck::violations($schema, 'ab'))->toBe([])
        ->and(SchemaCheck::violations($schema, 'abc'))->toBe([])
        ->and(SchemaCheck::violations($schema, 'a'))->toBe(['$: 1 characters, under the minLength 2'])
        ->and(SchemaCheck::violations($schema, 'abcd'))->toBe(['$: 4 characters, over the maxLength 3']);
});

it('names a list with fewer or more items than its bounds', function () {
    $schema = ['type' => 'array', 'minItems' => 1, 'maxItems' => 2];

    expect(SchemaCheck::violations($schema, [1]))->toBe([])
        ->and(SchemaCheck::violations($schema, [1, 2]))->toBe([])
        ->and(SchemaCheck::violations($schema, []))->toBe(['$: 0 items, under the minItems 1'])
        ->and(SchemaCheck::violations($schema, [1, 2, 3]))->toBe(['$: 3 items, over the maxItems 2']);
});

it('names a date that is not a calendar date in Y-m-d', function () {
    $schema = ['type' => 'string', 'format' => 'date'];

    expect(SchemaCheck::violations($schema, '2027-01-02'))->toBe([])
        ->and(SchemaCheck::violations($schema, 'Oct 11, 2026'))->toBe(['$: "Oct 11, 2026" is not a date (Y-m-d)'])
        ->and(SchemaCheck::violations($schema, '2026-02-30'))->toBe(['$: "2026-02-30" is not a date (Y-m-d)']);
});
