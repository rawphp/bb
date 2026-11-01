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
