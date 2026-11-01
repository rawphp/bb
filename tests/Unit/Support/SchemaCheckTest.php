<?php

use Tests\Support\SchemaCheck;

$schema = [
    'type' => 'object',
    'additionalProperties' => false,
    'properties' => [
        'title' => ['type' => 'string'],
        'kcal' => ['type' => ['integer', 'null'], 'minimum' => 0],
        'tone' => ['type' => 'string', 'enum' => ['good', 'warning']],
        'rows' => [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => ['value' => ['type' => 'number']],
                'required' => ['value'],
            ],
        ],
    ],
    'required' => ['title', 'kcal', 'tone', 'rows'],
];

it('accepts a value that fits the schema', function () use ($schema) {
    $value = ['title' => 'Today', 'kcal' => null, 'tone' => 'good', 'rows' => [['value' => 1.5], ['value' => 2]]];

    expect(SchemaCheck::problems($schema, $value))->toBe([]);
});

it('accepts an empty list', function () use ($schema) {
    expect(SchemaCheck::problems($schema, ['title' => 'T', 'kcal' => 1, 'tone' => 'good', 'rows' => []]))->toBe([]);
});

it('names each missing, extra, mistyped, out-of-range or unlisted value', function () use ($schema) {
    $value = ['kcal' => -1, 'tone' => 'bad', 'rows' => [['value' => 'x']], 'extra' => true];

    expect(SchemaCheck::problems($schema, $value))->toBe([
        '$.title: required',
        '$.kcal: -1 is below 0',
        '$.tone: "bad" is not one of ["good","warning"]',
        '$.rows[0].value: expected number, got string',
        '$.extra: not in the schema',
    ]);
});
